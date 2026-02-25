<?php

namespace App\Modules\Api\V1\Workflow\Actions;

use App\Models\WorkflowQueue;
use App\Services\CRM\RecordObject;
use Illuminate\Support\Facades\Log;

class UpdateFieldAction implements WorkflowActionInterface
{
    /**
     * Execute the workflow action.
     */
    public function handle(WorkflowQueue $job): void
    {
        $params = $job->params; // field, value, context[module, record_id]
        $context = $params['context'] ?? null;

        if (!$context) {
            Log::error("UpdateFieldAction: Missing context for job {$job->id}");
            return;
        }

        try {
            $record = RecordObject::make($context['module'], $context['record_id']);

            // Perform the update
            $record->fill([
                $params['field'] => $params['value']
            ]);

            $record->save();

            Log::info("UpdateFieldAction: Updated {$params['field']} to {$params['value']} for {$context['module']}:{$context['record_id']}");

        } catch (\Throwable $e) {
            Log::error("UpdateFieldAction Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate and prepare Update Field action parameters.
     */
    public function save(array $params, string $module, string $orgId): array
    {
        $field = $params['field'] ?? null;
        if (!$field) {
            throw new \Exception("Field name is required for update action.");
        }

        if (
            !\Schema::hasColumn(\Str::snake(\Str::plural($module)), $field) &&
            !\Schema::hasColumn(\Str::snake($module), $field)
        ) {
            throw new \Exception("Field '{$field}' does not exist in module '{$module}'.");
        }

        if (!isset($params['value'])) {
            throw new \Exception("Field value is required for update action.");
        }

        return $params;
    }
}
