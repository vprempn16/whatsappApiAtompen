<?php

namespace App\Modules\Api\V1\Zapier\Controllers;

use App\Http\Controllers\ApiController;
use App\Jobs\Zapier\ProcessZapierCachedRecord;
use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Models\ZapierWebhookCache;
use App\Modules\Api\V1\Zapier\Services\ZapierCachedImportService;
use App\Modules\Api\V1\Zapier\Services\ZapierFieldMapperService;
use App\Services\AuditLogService;
use App\Constants\AuditLogEventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZapierCachedImportController extends ApiController
{
    public function __construct(
        protected ZapierCachedImportService $cachedImportService,
        protected ZapierFieldMapperService $fieldMapperService
    ) {
    }

    /**
     * Log Zapier action to audit log
     */
    protected function logZapierAction(string $action, array $metadata = [], ?string $eventType = AuditLogEventType::UPDATE): void
    {
        try {
            $auditService = new AuditLogService();
            $organizationId = auth()->user()->organization_id ?? null;
            
            $auditService->create([
                'entity_name' => 'Zapier',
                'entity_id' => $organizationId ?? 'system',
                'organization_id' => $organizationId,
                'old_values' => [],
                'new_values' => $metadata,
                'is_update' => true,
                'more_info' => array_merge([
                    'action' => $action,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ], $metadata),
            ], $action, $eventType);
        } catch (\Exception $e) {
            Log::error('Failed to log Zapier action', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function listBatches(Request $request): JsonResponse
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $query = ZapierImportBatch::forOrganization($organizationId)
                ->latest('created_at')
                ->withCount(['requestLogs']);

            // Filter by module if provided
            if ($request->has('module')) {
                $query->forModule($request->input('module'));
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            $batches = $query->paginate($request->get('per_page', 15));

            $this->logZapierAction('Zapier cached import batches listed', [
                'module' => $request->input('module'),
                'status' => $request->input('status'),
                'total_batches' => $batches->total(),
                'per_page' => $request->get('per_page', 15),
            ], AuditLogEventType::UPDATE);

            return $this->success($batches);
        } catch (\Exception $e) {
            return $this->error('Failed to fetch batches: ' . $e->getMessage());
        }
    }

    public function listRecords(string $batchId, Request $request): JsonResponse
    {
        try {
            $organizationId = auth()->user()->organization_id;

            // Verify batch belongs to organization
            $batch = ZapierImportBatch::forOrganization($organizationId)->findOrFail($batchId);

            $records = ZapierWebhookCache::forBatch($batchId)
                ->forOrganization($organizationId)
                ->orderBy('record_index')
                ->paginate($request->get('per_page', 20));

            $this->logZapierAction('Zapier cached import records listed', [
                'batch_id' => $batchId,
                'module' => $batch->module,
                'total_records' => $records->total(),
                'per_page' => $request->get('per_page', 20),
            ], AuditLogEventType::UPDATE);

            return $this->success($records);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Batch not found');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch records: ' . $e->getMessage());
        }
    }

    public function showRecord(string $recordId): JsonResponse
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $cache = ZapierWebhookCache::forOrganization($organizationId)->findOrFail($recordId);

            $this->logZapierAction('Zapier cached import record viewed', [
                'record_id' => $recordId,
                'batch_id' => $cache->batch_id,
                'module' => $cache->module,
                'status' => $cache->status,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'record' => $cache,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Record not found');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch record: ' . $e->getMessage());
        }
    }

    public function moduleMetadata(string $batchId): JsonResponse
    {
        try {
            $organizationId = auth()->user()->organization_id;

            // Verify batch belongs to organization
            $batch = ZapierImportBatch::forOrganization($organizationId)->findOrFail($batchId);
            $fields = $this->fieldMapperService->getModuleFields($batch->module, $batch->organization_id);

            $this->logZapierAction('Zapier module metadata retrieved', [
                'batch_id' => $batchId,
                'module' => $batch->module,
                'fields_count' => count($fields['fieldMetadata'] ?? []),
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'module' => $batch->module,
                'fields' => $fields['fieldMetadata'] ?? [],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Batch not found');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch metadata: ' . $e->getMessage());
        }
    }

    public function submitMapping(string $recordId, Request $request): JsonResponse
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $cache = ZapierWebhookCache::forOrganization($organizationId)->findOrFail($recordId);
            $mapping = $request->input('mapping', []);
            $markFailed = $request->boolean('mark_failed', false);

            // Capture old mapping for audit log
            $oldMapping = $cache->field_mapping ? json_decode($cache->field_mapping, true) : [];

            $cache = $this->cachedImportService->applyMappingAndProcess($cache, $mapping, $markFailed);

            $this->logZapierAction('Zapier field mapping submitted', [
                'record_id' => $recordId,
                'batch_id' => $cache->batch_id,
                'module' => $cache->module,
                'mapping_fields_count' => count($mapping),
                'mark_failed' => $markFailed,
                'old_mapping' => $oldMapping,
                'new_mapping' => $mapping,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'record' => $cache,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Record not found');
        } catch (\Exception $e) {
            return $this->error('Failed to submit mapping: ' . $e->getMessage());
        }
    }

    public function triggerProcessing(string $recordId): JsonResponse
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $cache = ZapierWebhookCache::forOrganization($organizationId)->findOrFail($recordId);
            ProcessZapierCachedRecord::dispatch($recordId);

            $this->logZapierAction('Zapier record processing triggered', [
                'record_id' => $recordId,
                'batch_id' => $cache->batch_id,
                'module' => $cache->module,
                'status' => $cache->status,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'message' => 'Processing queued',
                'record_id' => $recordId,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Record not found');
        } catch (\Exception $e) {
            return $this->error('Failed to trigger processing: ' . $e->getMessage());
        }
    }
}
