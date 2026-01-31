<?php

namespace App\Modules\Api\V1\Zapier\Controllers;

use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Zapier\Models\ZapierImportBatch;
use App\Modules\Api\V1\Zapier\Models\ZapierRequestLog;
use App\Jobs\Zapier\ProcessZapierImportBatch;
use App\Services\AuditLogService;
use App\Constants\AuditLogEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZapierImportLogController extends ApiController
{
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

    /**
     * List import batches
     */
    public function index(Request $request)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $query = ZapierImportBatch::forOrganization($organizationId)
                ->orderBy('created_at', 'desc');

            // Filter by module
            if ($request->has('module')) {
                $query->forModule($request->input('module'));
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            $perPage = $request->input('per_page', 20);
            $batches = $query->paginate($perPage);

            $this->logZapierAction('Zapier import batches listed', [
                'module' => $request->input('module'),
                'status' => $request->input('status'),
                'total_batches' => $batches->total(),
                'per_page' => $perPage,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'batches' => $batches->items(),
                'meta' => [
                    'current_page' => $batches->currentPage(),
                    'last_page' => $batches->lastPage(),
                    'per_page' => $batches->perPage(),
                    'total' => $batches->total(),
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Failed to fetch import batches: ' . $e->getMessage());
        }
    }

    /**
     * Get batch details
     */
    public function show(string $id)
    {
        try {
            $organizationId = auth()->user()->organization_id;
            $batch = ZapierImportBatch::forOrganization($organizationId)->findOrFail($id);

            // Get request logs for this batch
            $logs = ZapierRequestLog::forOrganization($organizationId)
                ->forModule($batch->module)
                ->forExternalSource($batch->external_source)
                ->where('sync_mode', $batch->sync_mode)
                ->whereDate('created_at', $batch->created_at->format('Y-m-d'))
                ->orderBy('created_at', 'desc')
                ->paginate(50);

            $successCount = ZapierRequestLog::forOrganization($organizationId)
                ->forModule($batch->module)
                ->forExternalSource($batch->external_source)
                ->where('sync_mode', $batch->sync_mode)
                ->whereDate('created_at', $batch->created_at->format('Y-m-d'))
                ->successful()
                ->count();

            $failedCount = ZapierRequestLog::forOrganization($organizationId)
                ->forModule($batch->module)
                ->forExternalSource($batch->external_source)
                ->where('sync_mode', $batch->sync_mode)
                ->whereDate('created_at', $batch->created_at->format('Y-m-d'))
                ->failed()
                ->count();

            $this->logZapierAction('Zapier import batch details viewed', [
                'batch_id' => $batch->id,
                'module' => $batch->module,
                'external_source' => $batch->external_source,
                'sync_mode' => $batch->sync_mode,
                'status' => $batch->status,
                'total_records' => $successCount + $failedCount,
                'successful' => $successCount,
                'failed' => $failedCount,
            ], AuditLogEventType::UPDATE);

            return $this->success([
                'batch' => [
                    'id' => $batch->id,
                    'module' => $batch->module,
                    'external_source' => $batch->external_source,
                    'sync_mode' => $batch->sync_mode,
                    'status' => $batch->status,
                    'started_at' => $batch->started_at,
                    'completed_at' => $batch->completed_at,
                    'duration' => $batch->getDuration(),
                    'statistics' => [
                        'total' => $successCount + $failedCount,
                        'successful' => $successCount,
                        'failed' => $failedCount,
                    ],
                ],
                'logs' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Import batch not found');
        } catch (\Exception $e) {
            return $this->error('Failed to fetch batch details: ' . $e->getMessage());
        }
    }

    /**
     * Retry failed batch
     */
    public function retry(string $id)
    {
        try {
            $organizationId = auth()->user()->organization_id;

            $batch = ZapierImportBatch::forOrganization($organizationId)->findOrFail($id);

            if ($batch->status === 'running') {
                return $this->error('Batch is already running');
            }

            // Create new batch with same parameters
            $newBatch = ZapierImportBatch::create([
                'organization_id' => $batch->organization_id,
                'module' => $batch->module,
                'external_source' => $batch->external_source,
                'sync_mode' => $batch->sync_mode,
                'status' => 'running',
                'started_at' => now(),
            ]);

            // Dispatch processing job
            ProcessZapierImportBatch::dispatch(
                $newBatch->id,
                $batch->organization_id,
                $batch->module,
                $batch->external_source,
                $batch->sync_mode
            );

            $this->logZapierAction('Zapier import batch retry initiated', [
                'original_batch_id' => $batch->id,
                'new_batch_id' => $newBatch->id,
                'module' => $batch->module,
                'external_source' => $batch->external_source,
                'sync_mode' => $batch->sync_mode,
            ], AuditLogEventType::CREATE);

            return $this->success([
                'message' => 'Batch retry initiated',
                'batch_id' => $newBatch->id,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('Import batch not found');
        } catch (\Exception $e) {
            return $this->error('Failed to retry batch: ' . $e->getMessage());
        }
    }
}
