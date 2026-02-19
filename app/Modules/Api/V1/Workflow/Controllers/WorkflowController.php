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
            'actions' => 'required|array|min:1',
            'actions.*.action_type_id' => 'required|uuid',
            'actions.*.params' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
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

            // 4. Create Actions
            foreach ($request->actions as $index => $act) {
                $workflow->actions()->create([
                    'action_type_id' => $act['action_type_id'],
                    'params' => $act['params'] ?? [],
                    'execution_order' => $index,
                ]);
            }

            \DB::commit();

            return $this->success($workflow->load(['triggers', 'conditions', 'actions']), 'Workflow created successfully');

        } catch (\Throwable $e) {
            \DB::rollBack();
            return $this->errorFromException($e, 'Failed to create workflow');
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
