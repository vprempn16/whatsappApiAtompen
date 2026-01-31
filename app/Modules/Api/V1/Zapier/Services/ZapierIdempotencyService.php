<?php

namespace App\Modules\Api\V1\Zapier\Services;

use App\Modules\Api\V1\Zapier\Models\ZapierRequestLog;
use App\Modules\Api\V1\Lead\Models\Lead;
use App\Modules\Api\V1\Contact\Models\Contact;
use App\Modules\Api\V1\Product\Models\Product;
use Illuminate\Support\Facades\Log;

class ZapierIdempotencyService
{
    /**
     * Check if record already exists and return existing record ID
     * 
     * @param string $organizationId
     * @param string $module
     * @param array $zapierData Zapier data with external_id
     * @return string|null Existing record ID or null if new
     */
    public function findExistingRecord(string $organizationId, string $module, array $zapierData): ?string
    {
        $externalId = $zapierData['external_id'] ?? $zapierData['id'] ?? null;

        // Strategy 1: Check by external_id in zapier_request_logs
        if ($externalId) {
            $existingLog = ZapierRequestLog::findByExternalId($organizationId, $module, $externalId);
            if ($existingLog) {
                // Try to extract record ID from payload if stored
                $payload = $existingLog->payload ?? [];
                if (isset($payload['record_id'])) {
                    return $payload['record_id'];
                }
            }
        }

        // Strategy 2: Fallback matching by module-specific fields
        return $this->findByFallbackMatching($organizationId, $module, $zapierData);
    }

    /**
     * Find existing record using fallback matching strategies
     */
    protected function findByFallbackMatching(string $organizationId, string $module, array $zapierData): ?string
    {
        return match ($module) {
            'contacts', 'leads' => $this->findContactOrLead($organizationId, $module, $zapierData),
            'products' => $this->findProduct($organizationId, $zapierData),
            default => null,
        };
    }

    /**
     * Find Contact or Lead by email or phone
     */
    protected function findContactOrLead(string $organizationId, string $module, array $zapierData): ?string
    {
        $email = $zapierData['email'] ?? $zapierData['email_address'] ?? null;
        $phone = $zapierData['phone'] ?? $zapierData['phone_number'] ?? $zapierData['phoneNumber'] ?? null;

        $modelClass = $module === 'contacts' ? Contact::class : Lead::class;

        // Try email first
        if ($email) {
            $record = $modelClass::where('organization_id', $organizationId)
                ->where('email', $email)
                ->where('deleted', 0)
                ->first();

            if ($record) {
                return $record->id;
            }
        }

        // Try phone
        if ($phone) {
            $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);
            $record = $modelClass::where('organization_id', $organizationId)
                ->where(function ($query) use ($normalizedPhone) {
                    $query->where('phone_number', $normalizedPhone)
                          ->orWhere('phone_number', 'like', '%' . $normalizedPhone . '%');
                })
                ->where('deleted', 0)
                ->first();

            if ($record) {
                return $record->id;
            }
        }

        return null;
    }

    /**
     * Find Product by SKU
     */
    protected function findProduct(string $organizationId, array $zapierData): ?string
    {
        $sku = $zapierData['sku'] ?? $zapierData['sku_code'] ?? $zapierData['skuCode'] ?? null;

        if (!$sku) {
            return null;
        }

        $record = Product::where('organization_id', $organizationId)
            ->where('sku_code', $sku)
            ->where('deleted', 0)
            ->first();

        return $record ? $record->id : null;
    }

    /**
     * Log successful import for idempotency tracking
     */
    public function logSuccessfulImport(
        string $organizationId,
        string $module,
        string $externalSource,
        string $externalId,
        string $syncMode,
        string $recordId,
        array $payload
    ): void {
        ZapierRequestLog::create([
            'organization_id' => $organizationId,
            'module' => $module,
            'external_source' => $externalSource,
            'external_id' => $externalId,
            'sync_mode' => $syncMode,
            'status' => 'success',
            'payload' => array_merge($payload, ['record_id' => $recordId]),
            'created_at' => now(),
        ]);
    }

    /**
     * Log failed import
     */
    public function logFailedImport(
        string $organizationId,
        string $module,
        string $externalSource,
        string $externalId,
        string $syncMode,
        string $errorMessage,
        array $payload
    ): void {
        // Check if a log entry already exists for this external_id (any status) to avoid duplicates
        $existingLog = ZapierRequestLog::where('organization_id', $organizationId)
            ->where('module', $module)
            ->where('external_id', $externalId)
            ->first();
        
        if ($existingLog) {
            // Update existing log with new error information
            $existingLog->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'payload' => $payload,
            ]);
            Log::channel('zapier')->info('Updated existing Zapier request log for failed import', [
                'organization_id' => $organizationId,
                'module' => $module,
                'external_id' => $externalId,
                'log_id' => $existingLog->id,
            ]);
        } else {
            // Create new log entry
            try {
                ZapierRequestLog::create([
                    'organization_id' => $organizationId,
                    'module' => $module,
                    'external_source' => $externalSource,
                    'external_id' => $externalId,
                    'sync_mode' => $syncMode,
                    'status' => 'failed',
                    'error_message' => $errorMessage,
                    'payload' => $payload,
                    'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // If duplicate entry error, try to update existing record
                if ($e->getCode() === '23000') {
                    $existingLog = ZapierRequestLog::where('organization_id', $organizationId)
                        ->where('module', $module)
                        ->where('external_id', $externalId)
                        ->first();
                    
                    if ($existingLog) {
                        $existingLog->update([
                            'status' => 'failed',
                            'error_message' => $errorMessage,
                            'payload' => $payload,
                        ]);
                    }
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Check if external_id was already successfully imported
     */
    public function wasAlreadyImported(string $organizationId, string $module, string $externalId): bool
    {
        return ZapierRequestLog::existsForExternalId($organizationId, $module, $externalId);
    }
}
