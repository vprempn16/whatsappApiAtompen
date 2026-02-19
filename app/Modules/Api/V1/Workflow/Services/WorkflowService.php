<?php

namespace App\Modules\Api\V1\Workflow\Services;

use App\Modules\Api\V1\Workflow\Models\Workflow;
use App\Modules\Api\V1\Workflow\Models\WorkflowTrigger;
use App\Models\WorkflowQueue;
use App\Models\WorkflowActionType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkflowService
{
    protected static $isExecuting = false;

    /**
     * Trigger workflows for a specific module and event.
     */
    public function trigger(string $module, string $event, array $data)
    {
        if (self::$isExecuting)
            return;

        $orgId = $data['organization_id'] ?? (auth()->user()->organization_id ?? null);

        if (!$orgId)
            return;

        $triggers = WorkflowTrigger::where('module_name', $module)
            ->where('event_type', $event)
            ->where('organization_id', $orgId)
            ->with(['workflow.conditions', 'workflow.actions'])
            ->get();

        self::$isExecuting = true;

        foreach ($triggers as $trigger) {
            $workflow = $trigger->workflow;
            if ($workflow && $workflow->status) {
                if ($this->evaluateConditions($workflow, $data)) {
                    $this->queueActions($workflow, $data);
                }
            }
        }

        self::$isExecuting = false;
    }

    /**
     * Evaluate workflow conditions against the provided context data.
     */
    protected function evaluateConditions(Workflow $workflow, array $data): bool
    {
        $conditions = $workflow->conditions;
        if ($conditions->isEmpty()) {
            return true;
        }

        $allMatch = true;
        $anyMatch = false;

        foreach ($conditions as $condition) {
            $field = $condition->field_name;
            $op = $condition->operator;
            $val = $condition->value;
            $recordVal = $data['new_values'][$field] ?? null;

            $match = $this->compare($recordVal, $op, $val);

            if ($condition->logic === 'OR') {
                if ($match)
                    $anyMatch = true;
            } else {
                if (!$match)
                    $allMatch = false;
            }
        }

        // Simplistic logic: if any OR matched, it's a pass. Otherwise depends on ANDs.
        return $anyMatch || $allMatch;
    }

    /**
     * Compare two values using the specified operator.
     */
    protected function compare($recordVal, $op, $val): bool
    {
        switch ($op) {
            case '==':
                return $recordVal == $val;
            case '!=':
                return $recordVal != $val;
            case 'contains':
                return str_contains((string) $recordVal, (string) $val);
            case '>':
                return $recordVal > $val;
            case '<':
                return $recordVal < $val;
            case 'empty':
                return empty($recordVal);
            case 'not_empty':
                return !empty($recordVal);
            default:
                return false;
        }
    }

    /**
     * Queue workflow actions into the WorkflowQueue.
     */
    protected function queueActions(Workflow $workflow, array $data)
    {
        foreach ($workflow->actions as $idx => $action) {
            Log::info("WorkflowService: Queueing action {$idx}", [
                'action_params' => $action->params,
                'action_type_id' => $action->action_type_id
            ]);
            $userId = auth()->id() ?? ($data['new_values']['created_by'] ?? ($data['new_values']['assigned_user_id'] ?? null));

            // Fallback for MailboxService requirement (must be string)
            if (!$userId) {
                $userId = '00000000-0000-0000-0000-000000000000'; // System User Placeholder
            }

            $actionParams = is_array($action->params) ? $action->params : (json_decode($action->params, true) ?: []);

            $mergedParams = array_merge($actionParams, [
                'context' => [
                    'module' => $data['module'],
                    'record_id' => $data['entity_id'],
                    'values' => $data['new_values']
                ]
            ]);

            Log::info("WorkflowService: Merged params", ['params' => $mergedParams]);

            $job = WorkflowQueue::create([
                'id' => (string) Str::uuid(),
                'organization_id' => $workflow->organization_id,
                'user_id' => $userId,
                'type' => $this->getActionType($action->action_type_id),
                'params' => $mergedParams,
                'status' => 'pending',
                'related_module' => $data['module'],
                'related_record_id' => $data['entity_id'],
                'scheduled_at' => now(),
            ]);

            Log::info("WorkflowService: Queued action successfully", ['job_id' => $job->id ?? 'unknown']);
        }
    }

    protected function getActionType($typeId)
    {
        return \Illuminate\Support\Facades\DB::table('workflow_action_types')
            ->where('id', $typeId)
            ->value('action_type') ?? 'unknown';
    }
}
