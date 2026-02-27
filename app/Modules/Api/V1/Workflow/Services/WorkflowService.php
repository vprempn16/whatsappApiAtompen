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

        // Logic: (All AND conditions must match) AND (At least one OR condition must match if any exist)
        $andConditions = $conditions->where('logic', 'AND');
        $orConditions = $conditions->where('logic', 'OR');

        // 1. All AND conditions must match
        foreach ($andConditions as $condition) {
            if (!$this->isMatch($condition, $data)) {
                return false;
            }
        }

        // 2. If OR conditions exist, at least one must match
        if ($orConditions->isNotEmpty()) {
            foreach ($orConditions as $condition) {
                if ($this->isMatch($condition, $data)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Check if a single condition matches.
     */
    protected function isMatch($condition, array $data): bool
    {
        $module = $data['module'] ?? '';
        $apiField = $condition->field_name;

        // Resolve database field name (e.g., firstName -> first_name)
        $fieldName = $apiField;
        if (!isset($data['new_values'][$apiField])) {
            $fieldId = \App\Models\FieldModelManager::getFieldId($module, $apiField);
            if ($fieldId) {
                $crmField = \App\Models\CrmField::find($fieldId);
                if ($crmField) {
                    $fieldName = $crmField->fieldname;
                }
            }
        }

        $recordVal = $data['new_values'][$fieldName] ?? ($data['new_values'][$apiField] ?? null);
        $oldVal = $data['old_values'][$fieldName] ?? ($data['old_values'][$apiField] ?? null);
        return $this->compare($recordVal, $condition->operator, $condition->value, $oldVal);
    }

    /**
     * Compare two values using the specified operator.
     */
    protected function compare($recordVal, $op, $val, $oldVal = null): bool
    {
        // Normalize operator: lowercase, replace spaces/hyphens with underscores
        $op = str_replace([' ', '-'], '_', strtolower((string) $op));

        // Normalize for case-insensitive string comparison where relevant
        $recordValStr = strtolower((string) $recordVal);
        $valStr = strtolower((string) $val);

        // Date normalization helper
        $asDate = function ($v) {
            try {
                return $v ? \Illuminate\Support\Carbon::parse($v)->startOfDay() : null;
            } catch (\Exception $e) {
                return null;
            }
        };

        switch ($op) {
            case '==':
            case 'equals':
                return $recordVal == $val;
            case '!=':
            case 'not_equals':
                return $recordVal != $val;
            case 'contains':
                return str_contains($recordValStr, $valStr);
            case 'starts_with':
                return str_starts_with($recordValStr, $valStr);
            case 'ends_with':
                return str_ends_with($recordValStr, $valStr);
            case '>':
                return $recordVal > $val;
            case '<':
                return $recordVal < $val;
            case 'empty':
            case 'is_empty':
                return empty($recordVal);
            case 'not_empty':
            case 'is_not_empty':
                return !empty($recordVal);

            // Date Operators
            case 'between':
                $dates = is_array($val) ? $val : explode(',', (string) $val);
                $recordDate = $asDate($recordVal);
                $start = $asDate($dates[0] ?? null);
                $end = $asDate($dates[1] ?? null);
                return $recordDate && $start && $end && $recordDate->between($start, $end);

            case 'before':
                $recordDate = $asDate($recordVal);
                $targetDate = $asDate($val);
                return $recordDate && $targetDate && $recordDate->lt($targetDate);

            case 'after':
                $recordDate = $asDate($recordVal);
                $targetDate = $asDate($val);
                return $recordDate && $targetDate && $recordDate->gt($targetDate);

            case 'is_today':
            case 'today':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isToday();

            case 'is_tomorrow':
            case 'tomorrow':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isTomorrow();

            case 'is_yesterday':
            case 'yesterday':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isYesterday();

            case 'less_than_days_ago':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isAfter(now()->subDays((int) $val)) && $recordDate->isBefore(now());

            case 'more_than_days_ago':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isBefore(now()->subDays((int) $val));

            case 'less_than_days_later':
            case 'in_less_than_days':
            case 'in_less_than':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isBefore(now()->addDays((int) $val)) && $recordDate->isAfter(now());

            case 'more_than_days_later':
            case 'in_more_than_days':
            case 'in_more_than':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->isAfter(now()->addDays((int) $val));

            case 'days_ago':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->equalTo(now()->subDays((int) $val)->startOfDay());

            case 'days_later':
                $recordDate = $asDate($recordVal);
                return $recordDate && $recordDate->equalTo(now()->addDays((int) $val)->startOfDay());

            // Checkbox/Boolean Operators
            case 'is_enabled':
            case 'enabled':
            case 'checked':
                return $recordVal == 1 || $recordVal === true || strtolower((string) $recordVal) === 'true' || strtolower((string) $recordVal) === 'enabled' || strtolower((string) $recordVal) === 'yes';

            case 'is_disabled':
            case 'disabled':
            case 'unchecked':
                return $recordVal == 0 || $recordVal === false || strtolower((string) $recordVal) === 'false' || strtolower((string) $recordVal) === 'disabled' || empty($recordVal) || strtolower((string) $recordVal) === 'no';

            // Has Changed Operators
            case 'has_changed':
                return $recordVal != $oldVal;

            case 'has_changed_to':
                return $recordVal != $oldVal && $recordVal == $val;

            case 'has_changed_from':
                return $recordVal != $oldVal && $oldVal == $val;

            default:
                Log::warning("WorkflowService: Unknown operator '{$op}'");
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

    /**
     * Validates a Workflow Action Type payload.
     *
     * @param array $input Data to validate
     * @param string $orgId Organization ID
     * @param string|null $ignoreId ID to ignore for uniqueness (useful for updates)
     * @return \Illuminate\Validation\Validator
     */
    public function validateActionType(array $input, string $orgId, ?string $ignoreId = null)
    {
        $rules = [
            'action_label' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('workflow_action_types')->where('organization_id', $orgId)
            ],
            'action_type' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('workflow_action_types')->where('organization_id', $orgId)
            ],
            'function_path' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('workflow_action_types')->where('organization_id', $orgId)
            ],
            'function_class' => [
                'required',
                'string',
                'max:255',
                \Illuminate\Validation\Rule::unique('workflow_action_types')->where('organization_id', $orgId)
            ],
            'description' => 'nullable|string',
            'status' => 'nullable|boolean',
        ];

        if ($ignoreId) {
            $rules['action_label'][3]->ignore($ignoreId);
            $rules['action_type'][3]->ignore($ignoreId);
            $rules['function_path'][3]->ignore($ignoreId);
            $rules['function_class'][3]->ignore($ignoreId);
        }

        return \Illuminate\Support\Facades\Validator::make($input, $rules);
    }
}
