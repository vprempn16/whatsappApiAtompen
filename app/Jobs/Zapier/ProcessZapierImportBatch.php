<?php

namespace App\Jobs\Zapier;

use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Models\OrganizationZapierSetting;
use App\Modules\Api\V1\Zapier\Services\ZapierApiClient;
use App\Modules\Api\V1\Zapier\Services\ZapierFieldMapperService;
use App\Modules\Api\V1\Zapier\Services\ZapierImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessZapierImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes
    public int $backoff = 30; // 30 seconds between retries

    protected string $batchId;
    protected string $organizationId;
    protected string $module;
    protected string $externalSource;
    protected string $syncMode;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $batchId,
        string $organizationId,
        string $module,
        string $externalSource,
        string $syncMode
    ) {
        $this->batchId = $batchId;
        $this->organizationId = $organizationId;
        $this->module = $module;
        $this->externalSource = $externalSource;
        $this->syncMode = $syncMode;
    }

    /**
     * Execute the job.
     */
    public function handle(
        ZapierFieldMapperService $fieldMapper,
        ZapierImportService $importService
    ): void {
        $batch = ZapierImportBatch::find($this->batchId);

        if (!$batch) {
            Log::error("Zapier import batch not found", ['batch_id' => $this->batchId]);
            return;
        }

        $batch->markAsStarted();

        try {
            // Get organization settings
            $settings = OrganizationZapierSetting::forOrganization($this->organizationId)->first();
            if (!$settings) {
                throw new \Exception("Zapier settings not found for organization");
            }

            // Initialize API client
            $apiClient = new ZapierApiClient($settings->zapier_api_key);

            // Get module fields dynamically (this builds the cache)
            $fieldMapper->getModuleFields($this->module, $this->organizationId);

            // Get last sync timestamp for incremental sync
            $lastSyncTimestamp = null;
            if ($this->syncMode === 'incremental') {
                $lastSyncTimestamp = $importService->getLastSyncTimestamp(
                    $this->organizationId,
                    $this->module,
                    $this->externalSource
                );
            }

            // Build endpoint (this would typically come from settings or config)
            $endpoint = $this->buildEndpoint($this->module, $this->externalSource);

            // Fetch records from Zapier API (paginated)
            $recordCount = 0;
            foreach ($apiClient->fetchRecordsPaginated($endpoint, [], $this->syncMode, $lastSyncTimestamp) as $records) {
                foreach ($records as $zapierRecord) {
                    // Dispatch individual record import job
                    ImportZapierRecord::dispatch(
                        $this->batchId,
                        $this->organizationId,
                        $this->module,
                        $this->externalSource,
                        $this->syncMode,
                        $zapierRecord
                    );
                    $recordCount++;
                }
            }

            Log::info("Zapier batch processing completed", [
                'batch_id' => $this->batchId,
                'module' => $this->module,
                'records_dispatched' => $recordCount,
            ]);

            // If no records were found, mark batch as completed immediately
            if ($recordCount === 0) {
                $batch->markAsCompleted();
                $importService->updateLastSyncTimestamp($this->organizationId, $this->externalSource);
            } else {
                // Store record count in batch for tracking (we'll use request logs to track completion)
                // Batch will be marked as completed by ImportZapierRecord jobs when all records are processed
                Log::info("Zapier batch dispatched records", [
                    'batch_id' => $this->batchId,
                    'records_dispatched' => $recordCount,
                    'note' => 'Batch completion will be tracked by individual record jobs',
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Zapier batch processing failed", [
                'batch_id' => $this->batchId,
                'module' => $this->module,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $batch->markAsFailed();
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Build endpoint URL for Zapier API
     * This is a placeholder - actual implementation would get this from settings
     */
    protected function buildEndpoint(string $module, string $externalSource): string
    {
        // In a real implementation, this would come from organization settings
        // or a connected app configuration
        return "/hooks/catch/{$externalSource}/{$module}";
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        $batch = ZapierImportBatch::find($this->batchId);
        if ($batch) {
            $batch->markAsFailed();
        }

        Log::error("Zapier batch job failed permanently", [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);
    }
}
