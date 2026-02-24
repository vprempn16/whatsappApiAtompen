<?php

namespace App\Modules\Api\V1\Workflow\Controllers;

use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Workflow\Services\WorkflowService;
use App\Modules\Api\V1\Workflow\Models\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WorkflowController extends ApiController
{
    protected $workflowService;

    public function __construct(WorkflowService $workflowService)
    {
        $this->workflowService = $workflowService;
    }

    /**
     * Store a new workflow definition.
     */

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger' => 'required|array',
            'trigger.event_type' => 'required|string|in:created,updated,deleted',
            'trigger.module_name' => 'required|string',
            'conditions' => 'nullable|array',
            'actions' => 'required|array|size:1',
            'actions.*.action_type_id' => 'required|uuid',
            'actions.*.params' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        // Validate Action Type and Organization before starting transaction
        $act = $request->actions[0];
        $actionType = \App\Modules\Api\V1\Workflow\Models\WorkflowActionType::where('id', $act['action_type_id'])
            ->where('organization_id', $orgId)
            ->first();

        if (!$actionType) {
            return $this->error("Action type not found or does not belong to your organization.");
        }

        try {
            \DB::beginTransaction();

            // 1. Create Workflow
            $workflow = Workflow::create([
                'name' => $request->name,
                'description' => $request->description,
                'status' => true,
            ]);

            // 2. Create Trigger
            $workflow->triggers()->create([
                'event_type' => $request->trigger['event_type'],
                'module_name' => $request->trigger['module_name'],
            ]);

            // 3. Create Conditions
            if (!empty($request->conditions)) {
                foreach ($request->conditions as $cond) {
                    $workflow->conditions()->create([
                        'field_name' => $cond['field_name'],
                        'operator' => $cond['operator'],
                        'value' => $cond['value'],
                        'logic' => $cond['logic'] ?? 'AND',
                    ]);
                }
            }

            // 4. Create Action (One)
            // Verify module compatibility
            $isAllowed = $actionType->modules()
                ->where('modulename', $request->trigger['module_name'])
                ->exists();

            if (!$isAllowed) {
                throw new \Exception("Action '{$actionType->action_label}' is not allowed for module '{$request->trigger['module_name']}'.");
            }

            // Dynamic save/validation call

            //dd($actionType, $class);
            $class = $actionType->function_path;
            if (!$class || !class_exists($class)) {
                throw new \Exception("Action class '{$class}' not found for action type '{$actionType->action_label}'.");
            }

            $actionInstance = app($class);
            if (!method_exists($actionInstance, 'save')) {
                throw new \Exception("Action class '{$class}' missing 'save' method.");
            }

            $validatedParams = $actionInstance->save($act['params'] ?? [], $request->trigger['module_name'], $orgId);

            $workflow->actions()->create([
                'action_type_id' => $act['action_type_id'],
                'params' => $validatedParams,
                'execution_order' => 0,
            ]);

            \DB::commit();

            return $this->success($workflow->load(['triggers', 'conditions', 'actions']), 'Workflow created successfully');

        } catch (\Throwable $e) {
            \DB::rollBack();
            return $this->error($e->getMessage());
        }
    }

    /**
     * Get a list of workflows for the organization.
     */
    public function index()
    {
        try {
            $workflows = Workflow::with(['triggers', 'conditions', 'actions'])->get();
            return $this->success($workflows, 'Workflows retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve workflows');
        }
    }
}
