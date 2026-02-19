<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WorkflowQueue;
use App\Services\Mail\MailboxService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Modules\Api\V1\Workflow\Models\WorkflowLog;

class ProcessWorkflowQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflow:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending workflow queue jobs using dynamic action handlers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $job = WorkflowQueue::where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$job) {
            $this->info('No pending jobs found.');
            return;
        }


        $job->update(['status' => 'processing', 'executed_at' => now(), 'attempts' => $job->attempts + 1]);

        try {
            // Fetch Action Definition
            $actionType = DB::table('workflow_action_types')
                ->where('action_type', $job->type)
                ->first();

            if (!$actionType) {
                throw new Exception("Unknown action type: {$job->type}");
            }

            $handlerClass = $actionType->function_path;

            if (!class_exists($handlerClass)) {
                throw new Exception("Handler class not found: {$handlerClass}");
            }

            // Resolve Handler from Container
            $handler = app($handlerClass);

            if (!($handler instanceof \App\Modules\Api\V1\Workflow\Actions\WorkflowActionInterface)) {
                throw new Exception("Handler class must implement WorkflowActionInterface");
            }

            // Execute Action
            $handler->handle($job);

            $job->update(['status' => 'completed']);

            // Audit Log
            WorkflowLog::create([
                'organization_id' => $job->organization_id,
                'task_id' => $job->id,
                'status' => 'success',
                'message' => "Successfully processed {$job->type}",
                'executed_at' => now(),
            ]);

            $this->info("Job {$job->id} ({$job->type}) processed successfully.");

        } catch (Exception $e) {
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            // Audit Log
            WorkflowLog::create([
                'organization_id' => $job->organization_id,
                'task_id' => $job->id,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'executed_at' => now(),
            ]);

            $this->error("Job {$job->id} failed: " . $e->getMessage());
        }
    }
}
