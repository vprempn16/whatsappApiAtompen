<?php

namespace App\Modules\Api\V1\Zapier\Services;

use App\Jobs\Zapier\ProcessZapierCachedRecord;
use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Models\ZapierWebhookCache;
use App\Modules\Api\V1\Zapier\Services\ZapierIdempotencyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class ZapierCachedImportService
{
    /**
     * Create cache rows for a batch of payloads.
     */
    public function cachePayloads(
        ZapierImportBatch $batch,
        string $organizationId,
        string $module,
        array $records,
        string $externalSource = 'zapier'
    ): Collection {
        $index = 0;
        $created = collect();

        foreach ($records as $record) {
            // external_id is optional - generate one if not provided
            $externalId = $record['external_id'] ?? $record['id'] ?? null;
            
            if (empty($externalId)) {
                // Generate a temporary external_id if not provided
                $externalId = 'zapier-' . time() . '-' . $index;
                $record['external_id'] = $externalId;
            }

            $created->push(
                ZapierWebhookCache::create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batch->id,
                    'organization_id' => $organizationId,
                    'module' => $module,
                    'external_source' => $externalSource,
                    'external_id' => (string) $externalId, // Ensure it's a string
                    'record_index' => $index++,
                    'status' => 'pending',
                    'raw_payload' => $record,
                ])
            );
        }

        Log::channel('zapier')->info('Cached Zapier webhook payloads', [
            'batch_id' => $batch->id,
            'organization_id' => $organizationId,
            'module' => $module,
            'count' => $created->count(),
        ]);

        return $created;
    }

    /**
     * Persist user mapping and trigger processing for a cached record.
     */
    public function applyMappingAndProcess(ZapierWebhookCache $cache, array $mapping, ?bool $markFailed = false): ZapierWebhookCache
    {
        $cache->fill([
            'mapping' => $mapping,
            'status' => $markFailed ? 'failed' : 'mapped',
            'error_message' => $markFailed ? ($mapping['error'] ?? 'User marked as failed') : null,
        ])->save();

        if (!$markFailed) {
            ProcessZapierCachedRecord::dispatch($cache->id);
        }

        return $cache->refresh();
    }

    /**
     * Process a cached record synchronously (used by the job).
     */
    public function processCacheRecord(
        ZapierWebhookCache $cache,
        ZapierFieldMapperService $fieldMapper,
        ZapierIdempotencyService $idempotencyService
    ): void
    {
        if (!in_array($cache->status, ['mapped', 'pending'], true)) {
            return;
        }

        $cache->update(['status' => 'processing']);

        try {
            DB::beginTransaction();

            $mappedPayload = !empty($cache->mapping)
                ? $this->mapUsingSubmittedMapping($cache)
                : $fieldMapper->mapZapierDataToCrm($cache->raw_payload, $cache->module, $cache->organization_id);

            // Transform values according to field meta
            $fields = $fieldMapper->getModuleFields($cache->module, $cache->organization_id);
            $normalized = $this->normalizeMappedPayload($mappedPayload, $fields, $fieldMapper);

            $validationErrors = $fieldMapper->validateRequiredFields($normalized, $fields);
            if (!empty($validationErrors)) {
                throw new \Exception('Validation failed: ' . implode(', ', $validationErrors));
            }

            // Ensure organization context - use base User model which implements Authenticatable
            $user = \App\Models\User::where('organization_id', $cache->organization_id)->first();
            if ($user) {
                // Login user for queue jobs
                \Illuminate\Support\Facades\Auth::shouldUse('web');
                \Illuminate\Support\Facades\Auth::login($user);
            } else {
                throw new \Exception("User not found for organization: {$cache->organization_id}");
            }

            $existingRecordId = $idempotencyService->findExistingRecord(
                $cache->organization_id,
                $cache->module,
                $cache->raw_payload
            );

            $crmModuleName = $this->getCrmModuleName($cache->module);
            $model = \App\Services\CRM\RecordObject::make($crmModuleName, $existingRecordId, $normalized, 'EditView');
            $model->save();

            // external_id is mandatory - should always be present
            $externalId = $cache->external_id ?? $cache->raw_payload['external_id'] ?? $cache->raw_payload['id'] ?? null;
            
            if (empty($externalId)) {
                throw new \Exception('external_id is required but missing from cached record. Record ID: ' . $cache->id);
            }

            $idempotencyService->logSuccessfulImport(
                $cache->organization_id,
                $cache->module,
                $cache->external_source,
                (string) $externalId,
                'incremental',
                (string) $model->id,
                $cache->raw_payload
            );

            $cache->update([
                'mapped_payload' => $normalized,
                'status' => 'processed',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            DB::commit();

            $this->markBatchIfComplete($cache->batch);

        } catch (\Throwable $e) {
            DB::rollBack();

            $cache->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // external_id is optional - generate one if not present
            $externalId = $cache->external_id ?? $cache->raw_payload['external_id'] ?? $cache->raw_payload['id'] ?? null;
            
            if (empty($externalId)) {
                // Generate a temporary external_id for error logging
                $externalId = 'zapier-' . $cache->id;
            }
            $idempotencyService->logFailedImport(
                $cache->organization_id,
                $cache->module,
                $cache->external_source,
                (string) $externalId,
                'incremental',
                $e->getMessage(),
                $cache->raw_payload ?? []
            );

            Log::channel('zapier')->error('Failed processing cached Zapier record', [
                'cache_id' => $cache->id,
                'batch_id' => $cache->batch_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map raw payload using submitted mapping array.
     */
    protected function mapUsingSubmittedMapping(ZapierWebhookCache $cache): array
    {
        $mapping = $cache->mapping ?? [];
        $raw = $cache->raw_payload ?? [];
        $result = [];

        foreach ($mapping as $targetField => $source) {
            if (is_array($source) && isset($source['value'])) {
                $result[$targetField] = $source['value'];
                continue;
            }

            if (is_string($source) && array_key_exists($source, $raw)) {
                $result[$targetField] = $raw[$source];
            }
        }

        return $result;
    }

    protected function normalizeMappedPayload(array $mapped, array $fields, ZapierFieldMapperService $fieldMapper): array
    {
        $normalized = [];

        foreach ($mapped as $apiName => $value) {
            $meta = $fields['fieldMetadata'][$apiName] ?? null;
            $fieldType = $meta['type'] ?? 'string';
            $normalized[$apiName] = $fieldMapper->transformValue($value, $fieldType);
        }

        return $normalized;
    }

    protected function markBatchIfComplete(?ZapierImportBatch $batch): void
    {
        if (!$batch) {
            return;
        }

        $pending = ZapierWebhookCache::where('batch_id', $batch->id)
            ->whereIn('status', ['pending', 'mapped', 'processing'])
            ->count();

        if ($pending === 0) {
            $batch->markAsCompleted();
        }
    }

    protected function getCrmModuleName(string $module): string
    {
        return match($module) {
            'contacts' => 'Contact',
            'leads' => 'Lead',
            'products' => 'Product',
            default => ucfirst($module),
        };
    }
}
