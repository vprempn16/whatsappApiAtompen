<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Filter;
use App\Services\FilterService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class FilterController extends ApiController
{
    /**
     * Get all filters for a module
     * GET /api/v1/filters?module=Contact
     */
    public function index(Request $request)
    {
        try {
            $moduleName = $request->query('module');

            if (!$moduleName) {
                return $this->error('Module name is required');
            }
            
            $user = auth()->user();
            if (!$user) {
                return $this->error('Unauthenticated user');
            }
            
            $permissionService = new \App\Services\PermissionService($user);
            $moduleAction = 'view';
            if (!$permissionService->hasPermission($moduleName, $moduleAction)) {
                return $this->error("Unauthorized: No {$moduleAction} permission for module {$moduleName}");
            }

            $filters = Filter::getForModule($moduleName);

            return $this->success($filters);
        } catch (\Exception $e) {
            Log::error("Error fetching filters: {$e->getMessage()}");
            return $this->error('Failed to fetch filters');
        }
    }

    /**
     * Get a single filter by ID
     * GET /api/v1/filters/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $filter = Filter::with('conditions')->find($id);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            // Check access
            if ($filter->organization_id !== auth()->user()->organization_id) {
                return $this->error('Unauthorized access');
            }

            return $this->success($filter->toApiFormat());
        } catch (\Exception $e) {
            Log::error("Error fetching filter: {$e->getMessage()}");
            return $this->error('Failed to fetch filter');
        }
    }

    /**
     * Create a new filter
     * POST /api/v1/filters/new
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
    'module_name'  => 'required|string',
    'name'         => 'sometimes|string|max:255',
    'description'  => 'nullable|string',
    'is_shared'    => 'boolean',
    'is_default'   => 'boolean',
    'header_details' => 'nullable|array',

    // ✅ OPTIONAL even on update
    'conditions' => 'nullable|array',

    'conditions.*.field_name'     => 'required_with:conditions|string',
    'conditions.*.operator_key'   => 'required_with:conditions|string',
    'conditions.*.value'          => 'nullable',
    'conditions.*.condition_type' => 'required_with:conditions|in:AND,OR',
]);


            if ($validator->fails()) {
                return $this->error('Validation failed');
            }

            $filter = Filter::createWithConditions($request->all());

            return $this->success($filter->toApiFormat(), 'Filter created successfully');
        } catch (\Exception $e) {
            Log::error("Error creating filter: {$e->getMessage()}");
            return $this->error("Failed to create filter : {$e->getMessage()}");
        }
    }

    /**
     * Update an existing filter
     * PUT /api/v1/filters/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $filter = Filter::find($id);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            // Check access
            if ($filter->organization_id !== auth()->user()->organization_id) {
                return $this->error('Unauthorized access');
            }

            // Only creator or shared filter can be edited
            if (!auth()->user()->is_admin &&
    $filter->created_by !== auth()->user()->id &&
    !$filter->is_shared
) {

                return $this->error('You do not have permission to edit this filter');
            }

            $validator = Validator::make($request->all(), [
    'module_name'  => 'sometimes|string',
    'name'         => 'sometimes|string|max:255',
    'description'  => 'nullable|string',
    'is_shared'    => 'boolean',
    'is_default'   => 'boolean',
    'header_details' => 'nullable|array',

    // ✅ OPTIONAL even on update
    'conditions' => 'nullable|array',

    'conditions.*.field_name'     => 'required_with:conditions|string',
    'conditions.*.operator_key'   => 'required_with:conditions|string',
    'conditions.*.value'          => 'nullable',
    'conditions.*.condition_type' => 'required_with:conditions|in:AND,OR',
]);


            if ($validator->fails()) {
                return $this->error('Validation failed');
            }

            $filter->updateWithConditions($request->all());

            return $this->success($filter->fresh()->toApiFormat(), 'Filter updated successfully');
        } catch (\Exception $e) {
            Log::error("Error updating filter: {$e->getMessage()}");
            return $this->error('Failed to update filter');
        }
    }

    /**
     * Delete a filter
     * DELETE /api/v1/filters/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $filter = Filter::find($id);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            // Check access
            if ($filter->organization_id !== auth()->user()->organization_id) {
                return $this->error('Unauthorized access');
            }

            // Only creator can delete
            if (
    !auth()->user()->is_admin &&
    $filter->created_by !== auth()->user()->id &&
    !$filter->is_shared
) {
                return $this->error('You do not have permission to delete this filter');
            }

            $filter->softDelete();

            return $this->success([], 'Filter deleted successfully');
        } catch (\Exception $e) {
            Log::error("Error deleting filter: {$e->getMessage()}");
            return $this->error('Failed to delete filter');
        }
    }

    /**
     * Set filter as default for the module
     * POST /api/v1/filters/{id}/set-default
     */
    public function setDefault(string $id): JsonResponse
    {
        try {
            $filter = Filter::find($id);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            if ($filter->organization_id !== auth()->user()->organization_id) {
                return $this->error('Unauthorized access');
            }

            $filter->setAsDefault();

            return $this->success($filter->fresh()->toApiFormat(), 'Filter set as default successfully');
        } catch (\Exception $e) {
            Log::error("Error setting default filter: {$e->getMessage()}");
            return $this->error('Failed to set default filter');
        }
    }

    /**
     * Duplicate a filter
     * POST /api/v1/filters/{id}/duplicate
     */
    public function duplicate(Request $request, string $id): JsonResponse
    {
        try {
            $filter = Filter::find($id);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            if ($filter->organization_id !== auth()->user()->organization_id) {
                return $this->error('Unauthorized access');
            }

            $newName = $request->input('name');
            $newFilter = $filter->duplicate($newName);

            if (!$newFilter) {
                return $this->error('Failed to duplicate filter');
            }

            return $this->success($newFilter->toApiFormat(), 'Filter duplicated successfully');
        } catch (\Exception $e) {
            Log::error("Error duplicating filter: {$e->getMessage()}");
            return $this->error('Failed to duplicate filter');
        }
    }

    /**
     * Get filtered records for a module
     * GET /api/v1/filters/{id}/records
     */
    public function getRecords(Request $request, string $id): JsonResponse
    {
        try {
            $filter = Filter::find($id);

            if (!$filter || $filter->deleted) {
                return $this->error('Filter not found');
            }

            if ($filter->organization_id !== auth()->user()->organization_id) {
                return $this->error('Unauthorized access');
            }

            $perPage = $request->query('per_page', 20);
            $page = $request->query('page', 1);

            $result = FilterService::getFilteredList(
                $filter->module_name,
                $filter->id,
                $perPage,
                $page
            );

            return $this->success([
                'filter_id' => $filter->id,
                'list' => $result['details'],
                'meta' => $result['meta'],
                'links' => $result['links']
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching filtered records: {$e->getMessage()}");
            return $this->error('Failed to fetch filtered records');
        }
    }

    /**
     * Get filter configuration for a module
     * GET /api/v1/filters/config
     */
    public function getConfig(Request $request): JsonResponse
    {
        try {
            $moduleName = $request->query('module');

            if (!$moduleName) {
                return $this->error('Module name is required');
            }

            $config = FilterService::getFilterConfig($moduleName);

            return $this->success($config);
        } catch (\Exception $e) {
            Log::error("Error fetching filter config: {$e->getMessage()}");
            return $this->error('Failed to fetch filter configuration');
        }
    }
}