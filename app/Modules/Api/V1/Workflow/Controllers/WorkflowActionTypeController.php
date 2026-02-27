<?php

namespace App\Modules\Api\V1\Workflow\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Modules\Api\V1\Workflow\Models\WorkflowActionType;
use App\Modules\Api\V1\Workflow\Services\WorkflowService;

class WorkflowActionTypeController extends ApiController
{
    /**
     * List all action types for the organization.
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        try {
            $query = WorkflowActionType::where('organization_id', $orgId);

            // Optionally filter by status
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            $types = $query->get();
            return $this->success($types, 'Action types retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve action types');
        }
    }

    /**
     * Store a new Workflow Action Type.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        $input = $request->input('data.values') ?? $request->all();

        $workflowService = new WorkflowService();
        $validator = $workflowService->validateActionType($input, $orgId);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            // Ensure function_path class actually exists
            if (!class_exists($input['function_path'])) {
                return $this->error("The class specified in function_path does not exist.");
            }

            $type = WorkflowActionType::create([
                'organization_id' => $orgId,
                'action_label' => $input['action_label'],
                'action_type' => $input['action_type'],
                'function_path' => $input['function_path'],
                'function_class' => $input['function_class'],
                'description' => $input['description'] ?? null,
                'status' => $input['status'] ?? true, // Default to true if not provided
            ]);

            return $this->success($type, 'Action type created successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to create action type');
        }
    }

    /**
     * Get a single Workflow Action Type.
     */
    public function show($id)
    {
        $orgId = auth()->user()->organization_id;

        try {
            $type = WorkflowActionType::where('id', $id)
                ->where('organization_id', $orgId)
                ->firstOrFail();

            return $this->success($type, 'Action type retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve action type');
        }
    }

    /**
     * Update an existing Workflow Action Type.
     */
    public function update(Request $request, $id)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        $input = $request->input('data.values') ?? $request->all();

        $workflowService = new WorkflowService();
        $validator = $workflowService->validateActionType($input, $orgId, $id);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            $type = WorkflowActionType::where('id', $id)
                ->where('organization_id', $orgId)
                ->firstOrFail();

            // Ensure function_path class actually exists if it's being updated
            if (!class_exists($input['function_path'])) {
                return $this->error("The class specified in function_path does not exist.");
            }

            $type->update([
                'action_label' => $input['action_label'],
                'action_type' => $input['action_type'],
                'function_path' => $input['function_path'],
                'function_class' => $input['function_class'],
                'description' => $input['description'] ?? $type->description,
                'status' => isset($input['status']) ? $input['status'] : $type->status,
            ]);

            return $this->success($type, 'Action type updated successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to update action type');
        }
    }

    /**
     * Delete an existing Workflow Action Type.
     */
    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;

        try {
            $type = WorkflowActionType::where('id', $id)
                ->where('organization_id', $orgId)
                ->firstOrFail();

            // Prevent deletion if it's currently used by active actions
            $isUsed = \App\Modules\Api\V1\Workflow\Models\WorkflowAction::where('action_type_id', $id)->exists();
            if ($isUsed) {
                return $this->error("Cannot delete Action Type because it is currently used in active workflows.");
            }

            $type->delete();
            return $this->success(null, 'Action type deleted successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to delete action type');
        }
    }
}
