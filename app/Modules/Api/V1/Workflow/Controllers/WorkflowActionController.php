<?php

namespace App\Modules\Api\V1\Workflow\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Modules\Api\V1\Workflow\Models\WorkflowAction;
use App\Modules\Api\V1\Workflow\Models\WorkflowActionType;
use App\Modules\Api\V1\Workflow\Models\Workflow;

class WorkflowActionController extends ApiController
{
    /**
     * List actions (optionally matching a workflow_id if passed)
     */
    public function index(Request $request)
    {
        try {
            $query = WorkflowAction::with('workflow');

            if ($request->has('workflow_id')) {
                $query->where('workflow_id', $request->input('workflow_id'));
            }

            $perPage = $request->input('per_page', 20);
            $paginator = $query->paginate($perPage);

            $data = [
                'actions' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'last' => $paginator->url($paginator->lastPage()),
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                ],
            ];

            return $this->success($data, 'Workflow actions retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve workflow actions');
        }
    }

    /**
     * Store a new Workflow Action tied to a Workflow.
     */
    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        $input = $request->input('data.values') ?? $request->all();

        $validator = Validator::make($input, [
            'workflow_id' => 'required|uuid',
            'action_type_id' => 'required|uuid',
            'title' => 'required|string|max:255',
            'params' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            \DB::beginTransaction();

            /** @var \App\Modules\Api\V1\Workflow\Models\Workflow $workflow */
            $workflow = Workflow::where('id', $input['workflow_id'])->firstOrFail();
            $actionType = WorkflowActionType::where('id', $input['action_type_id'])
                ->where('organization_id', $orgId)
                ->firstOrFail();

            $triggerModule = $workflow->triggers->first()?->module_name;
            if (!$triggerModule) {
                throw new \Exception("Workflow does not have a module trigger configured.");
            }

            $class = $actionType->function_path;
            if (!$class || !class_exists($class)) {
                throw new \Exception("Action class '{$class}' not found.");
            }

            $actionInstance = app($class);
            $validatedParams = method_exists($actionInstance, 'save')
                ? $actionInstance->save($input['params'] ?? [], $triggerModule, $orgId)
                : ($input['params'] ?? []);

            $action = $workflow->actions()->create([
                'action_type_id' => $input['action_type_id'],
                'title' => $input['title'] ?? null,
                'params' => $validatedParams,
                'execution_order' => $workflow->actions()->count(),
                'organization_id' => $orgId,
                'created_by' => auth()->id()
            ]);

            \DB::commit();

            return $this->success($action, 'Workflow action created successfully');
        } catch (\Throwable $e) {
            \DB::rollBack();
            return $this->errorFromException($e, 'Failed to create workflow action');
        }
    }

    /**
     * Update an existing Workflow Action.
     */
    public function update(Request $request, $id)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        $input = $request->input('data.values') ?? $request->all();

        $validator = Validator::make($input, [
            'title' => 'required|string|max:255',
            'params' => 'required|array',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        try {
            \DB::beginTransaction();

            $action = WorkflowAction::findOrFail($id);
            $workflow = $action->workflow;
            $triggerModule = $workflow->triggers->first()?->module_name ?? 'Unknown';

            $actionType = WorkflowActionType::findOrFail($action->action_type_id);
            $class = $actionType->function_path;

            $actionInstance = class_exists($class) ? app($class) : null;
            $validatedParams = ($actionInstance && method_exists($actionInstance, 'save'))
                ? $actionInstance->save($input['params'] ?? [], $triggerModule, $orgId)
                : ($input['params'] ?? []);

            $action->update([
                'title' => $input['title'] ?? $action->title,
                'params' => $validatedParams,
            ]);

            \DB::commit();

            return $this->success($action, 'Workflow action updated successfully');
        } catch (\Throwable $e) {
            \DB::rollBack();
            return $this->errorFromException($e, 'Failed to update workflow action');
        }
    }

    /**
     * Delete an existing Workflow Action.
     */
    public function destroy($id)
    {
        try {
            $action = WorkflowAction::findOrFail($id);
            $action->delete();
            return $this->success(null, 'Workflow action deleted successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to delete workflow action');
        }
    }

    /**
     * Returns the dynamic fields required for saving an action type.
     */
    public function getParams($action_type_id)
    {
        try {
            $actionType = WorkflowActionType::findOrFail($action_type_id);
            $class = $actionType->function_path;

            if (!$class || !class_exists($class)) {
                throw new \Exception("Action class not found.");
            }

            $actionInstance = app($class);
            if (!method_exists($actionInstance, 'getParamsFields')) {
                return $this->success([], 'No params configuration found for this action');
            }

            $fields = $actionInstance->getParamsFields();
            return $this->success($fields, 'Params fields retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve params for action type');
        }
    }

    /**
     * Get all available Action Types for the organization.
     */
    public function getActionTypes()
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId)
            return $this->error('Organization not found');

        try {
            $types = WorkflowActionType::where('organization_id', $orgId)
                ->where('status', 1) // Only active action types
                ->get();
            return $this->success($types, 'Action types retrieved successfully');
        } catch (\Throwable $e) {
            return $this->errorFromException($e, 'Failed to retrieve action types');
        }
    }
}
