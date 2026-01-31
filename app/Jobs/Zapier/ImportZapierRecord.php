<?php

namespace App\Jobs\Zapier;

use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Services\ZapierFieldMapperService;
use App\Modules\Api\V1\Zapier\Services\ZapierIdempotencyService;
use App\Services\CRM\RecordObject;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ImportZapierRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120; // 2 minutes per record
    public int $backoff = 10; // 10 seconds between retries

    protected string $batchId;
    protected string $organizationId;
    protected string $module;
    protected string $externalSource;
    protected string $syncMode;
    protected array $zapierRecord;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $batchId,
        string $organizationId,
        string $module,
        string $externalSource,
        string $syncMode,
        array $zapierRecord
    ) {
        $this->batchId = $batchId;
        $this->organizationId = $organizationId;
        $this->module = $module;
        $this->externalSource = $externalSource;
        $this->syncMode = $syncMode;
        $this->zapierRecord = $zapierRecord;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ZapierFieldMapperService $fieldMapper,
        ZapierIdempotencyService $idempotencyService
    ): void {
        // Set organization context
        $user = User::where('organization_id', $this->organizationId)->first();
        if (!$user) {
            throw new \Exception("User not found for organization: {$this->organizationId}");
        }
        // Login user for queue jobs - use login() which works with Authenticatable
        Auth::shouldUse('web');
        Auth::login($user);

        // external_id is optional - generate one if not provided
        $externalId = $this->zapierRecord['external_id'] ?? $this->zapierRecord['id'] ?? null;
        
        if (empty($externalId)) {
            // Generate a temporary external_id if not provided
            $externalId = 'zapier-' . time() . '-' . uniqid();
            $this->zapierRecord['external_id'] = $externalId;
        }

        try {
            DB::beginTransaction();

            // Check idempotency - find existing record
            $existingRecordId = $idempotencyService->findExistingRecord(
                $this->organizationId,
                $this->module,
                $this->zapierRecord
            );

            // Map Zapier fields to CRM fields
            $mappedData = $fieldMapper->mapZapierDataToCrm(
                $this->zapierRecord,
                $this->module,
                $this->organizationId
            );

            // Ensure organization_id is set
            $mappedData['organizationId'] = $this->organizationId;

            // Validate required fields
            $mappings = $fieldMapper->getModuleFields($this->module, $this->organizationId);
            $validationErrors = $fieldMapper->validateRequiredFields($mappedData, $mappings);

            if (!empty($validationErrors)) {
                throw new \Exception("Validation failed: " . implode(', ', $validationErrors));
            }

            // Map module name from database enum to CRM model name
            $crmModuleName = $this->getCrmModuleName($this->module);

            // Create or update record
            $model = RecordObject::make($crmModuleName, $existingRecordId, $mappedData, 'EditView');
            $model->save();

            // Log successful import
            $idempotencyService->logSuccessfulImport(
                $this->organizationId,
                $this->module,
                $this->externalSource,
                $externalId,
                $this->syncMode,
                $model->id,
                $this->zapierRecord
            );

            DB::commit();

            Log::info("Zapier record imported successfully", [
                'batch_id' => $this->batchId,
                'module' => $this->module,
                'external_id' => $externalId,
                'record_id' => $model->id,
                'action' => $existingRecordId ? 'updated' : 'created',
            ]);

            // Check if batch should be marked as completed
            $this->checkBatchCompletion();

        } catch (\Exception $e) {
            DB::rollBack();

            // Log failed import
            $idempotencyService->logFailedImport(
                $this->organizationId,
                $this->module,
                $this->externalSource,
                $externalId,
                $this->syncMode,
                $e->getMessage(),
                $this->zapierRecord
            );

            Log::error("Zapier record import failed", [
                'batch_id' => $this->batchId,
                'module' => $this->module,
                'external_id' => $externalId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Map module name from database enum to CRM model name
     */
    protected function getCrmModuleName(string $module): string
    {
        return match($module) {
            'contacts' => 'Contact',
            'leads' => 'Lead',
            'products' => 'Product',
            default => ucfirst($module),
        };
    }

    /**
     * Check if batch should be marked as completed
     * This checks if all records for the batch have been processed
     */
    protected function checkBatchCompletion(): void
    {
        $batch = ZapierImportBatch::find($this->batchId);
        if (!$batch || $batch->status !== 'running') {
            return;
        }

        // Get all request logs for this batch
        $totalLogs = \App\Modules\Api\V1\Zapier\Models\ZapierRequestLog::forOrganization($this->organizationId)
            ->forModule($this->module)
            ->forExternalSource($this->externalSource)
            ->where('sync_mode', $this->syncMode)
            ->whereDate('created_at', $batch->created_at->format('Y-m-d'))
            ->count();

        // Estimate: if we have logs and batch was created more than 5 minutes ago,
        // and no new logs in the last minute, consider it complete
        // This is a heuristic approach since we don't track exact record count
        if ($totalLogs > 0 && $batch->created_at->diffInMinutes(now()) >= 5) {
            $recentLogs = \App\Modules\Api\V1\Zapier\Models\ZapierRequestLog::forOrganization($this->organizationId)
                ->forModule($this->module)
                ->forExternalSource($this->externalSource)
                ->where('sync_mode', $this->syncMode)
                ->whereDate('created_at', $batch->created_at->format('Y-m-d'))
                ->where('created_at', '>=', now()->subMinute())
                ->count();

            // If no recent activity, mark batch as completed
            if ($recentLogs === 0) {
                $batch->markAsCompleted();
                
                // Update last sync timestamp
                $importService = app(\App\Modules\Api\V1\Zapier\Services\ZapierImportService::class);
                $importService->updateLastSyncTimestamp($this->organizationId, $this->externalSource);

                Log::info("Zapier batch marked as completed", [
                    'batch_id' => $this->batchId,
                    'total_records' => $totalLogs,
                ]);
            }
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        // external_id is optional
        $externalId = $this->zapierRecord['external_id'] ?? $this->zapierRecord['id'] ?? null;
        
        if (empty($externalId)) {
            $externalId = 'unknown-' . $this->batchId; // Fallback for error logging only
        }

        Log::error("Zapier record import job failed permanently", [
            'batch_id' => $this->batchId,
            'module' => $this->module,
            'external_id' => $externalId,
            'error' => $exception->getMessage(),
        ]);
    }
}
