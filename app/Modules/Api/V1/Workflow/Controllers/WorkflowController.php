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

        $input = $request->input('data.values') ?? $request->all();

        $validator = Validator::make($input, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger' => 'required|array',
            'trigger.event_type' => 'required|string|in:created,updated,deleted',
            'trigger.module_name' => 'required|string',
            'conditions' => 'nullable|array',
            'actions' => 'nullable|array',
            'actions.*.title' => 'required|string|max:255',
            'actions.*.action_type_id' => 'required|uuid',
            'actions.*.params' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        // Validating Action at creation is optional now.
        // It's handled below if provided.

        try {
            \DB::beginTransaction();

            // 1. Create Workflow
            $workflow = Workflow::create([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'status' => true,
            ]);

            // 2. Create Trigger
            $workflow->triggers()->create([
                'event_type' => $input['trigger']['event_type'],
                'module_name' => $input['trigger']['module_name'],
            ]);

            // 3. Create Conditions
            if (!empty($input['conditions'])) {
                foreach ($input['conditions'] as $cond) {
                    $workflow->conditions()->create([
                        'field_name' => $cond['field_name'],
                        'operator' => $cond['operator'],
                        'value' => $cond['value'],
                        'logic' => $cond['logic'] ?? 'AND',
                    ]);
                }
            }

            // 4. Create Action (Optional, can be added later via Action endpoints)
            if (!empty($input['actions'])) {
                $order = 0;
                foreach ($input['actions'] as $act) {
                    $actionType = \App\Modules\Api\V1\Workflow\Models\WorkflowActionType::where('id', $act['action_type_id'])
                        ->where('organization_id', $orgId)
                        ->first();

                    if (!$actionType)
                        continue;

                    $class = $actionType->function_path;
                    if ($class && class_exists($class)) {
                        $actionInstance = app($class);
                        if (method_exists($actionInstance, 'save')) {
                            $validatedParams = $actionInstance->save($act['params'] ?? [], $input['trigger']['module_name'], $orgId);
                            $workflow->actions()->create([
                                'action_type_id' => $act['action_type_id'],
                                'title' => $act['title'] ?? null,
                                'params' => $validatedParams,
                                'execution_order' => $order++,
                            ]);
                        }
                    }
                }
            }

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

    /**
     * Get a list of workflows for a specific module.
     */
    public function getByModule($module)
    {
        try {
            $workflows = Workflow::whereHas('triggers', function ($q) use ($module) {
                $q->where('module_name', $module);
            })->with(['triggers', 'conditions', 'actions'])->get();
            return $this->success($workflows, 'Workflows retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve workflows');
        }
    }

    /**
     * Get a single workflow.
     */
    public function show($id)
    {
        try {
            $workflow = Workflow::with(['triggers', 'conditions', 'actions'])->findOrFail($id);
            return $this->success($workflow, 'Workflow retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve workflow');
        }
    }

    /**
     * Update an existing workflow.
     */
    public function update(Request $request, $id)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        $input = $request->input('data.values') ?? $request->all();

        $validator = Validator::make($input, [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'trigger' => 'required|array',
            'trigger.event_type' => 'required|string|in:created,updated,deleted',
            'trigger.module_name' => 'required|string',
            'conditions' => 'nullable|array',
            'actions' => 'nullable|array',
            'actions.*.title' => 'required|string|max:255',
            'actions.*.action_type_id' => 'required|uuid',
            'actions.*.params' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            \DB::beginTransaction();

            $workflow = Workflow::findOrFail($id);

            $workflow->update([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
            ]);

            // Update Trigger
            $workflow->triggers()->delete();
            $workflow->triggers()->create([
                'event_type' => $input['trigger']['event_type'],
                'module_name' => $input['trigger']['module_name'],
            ]);

            // Update Conditions
            $workflow->conditions()->delete();
            if (!empty($input['conditions'])) {
                foreach ($input['conditions'] as $cond) {
                    $workflow->conditions()->create([
                        'field_name' => $cond['field_name'],
                        'operator' => $cond['operator'],
                        'value' => $cond['value'],
                        'logic' => $cond['logic'] ?? 'AND',
                    ]);
                }
            }

            // Update Actions
            // The cleanest way is to recreate them so the new execution order and params are fresh
            $workflow->actions()->delete();
            if (!empty($input['actions'])) {
                $order = 0;
                foreach ($input['actions'] as $act) {
                    $actionType = \App\Modules\Api\V1\Workflow\Models\WorkflowActionType::where('id', $act['action_type_id'])
                        ->where('organization_id', $orgId)
                        ->first();

                    if (!$actionType)
                        continue;

                    $class = $actionType->function_path;
                    if ($class && class_exists($class)) {
                        $actionInstance = app($class);
                        if (method_exists($actionInstance, 'save')) {
                            // Pre-process params through the Action's save validator
                            $validatedParams = $actionInstance->save($act['params'] ?? [], $input['trigger']['module_name'], $orgId);
                            $workflow->actions()->create([
                                'action_type_id' => $act['action_type_id'],
                                'title' => $act['title'] ?? null,
                                'params' => $validatedParams,
                                'execution_order' => $order++,
                                'organization_id' => $orgId,
                                'created_by' => auth()->id()
                            ]);
                        }
                    }
                }
            }

            \DB::commit();

            return $this->success($workflow->load(['triggers', 'conditions', 'actions']), 'Workflow updated successfully');
        } catch (\Throwable $e) {
            \DB::rollBack();
            return $this->errorFromException($e, 'Failed to update workflow');
        }
    }
}
