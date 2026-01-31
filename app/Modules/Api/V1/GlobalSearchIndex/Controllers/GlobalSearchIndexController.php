<?php

namespace App\Modules\Api\V1\GlobalSearchIndex\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Modules\Api\V1\GlobalSearchIndex\Models\GlobalSearchIndex;
use App\Modules\Api\V1\User\Models\User;

use App\Models\FieldModelManager;
use App\Services\PermissionService;
use App\Services\ModuleService;
use Illuminate\Support\Facades\DB;

class GlobalSearchIndexController extends ApiController
{
	public function filter(Request $request, $module)
	{
		  // Support both formats
    $value = $request->query('search')
        ?? ($request->get('search')['value'] ?? null);
		$user = auth()->user();
		$org_id = $user->organization_id;

		$permissionService = new PermissionService($user);
		if (!$permissionService->hasPermission($module, 'view')) {
			return $this->error('Unauthorized: No view permission for module ' . $module);
		}

		if (empty($value)) {
			return $this->error('Search value is required');
		}
 if ($module === 'User') {
        $query = User::query()
            ->where('organization_id', $org_id)
            ->where('deleted', 0);

        // Sanitize search value to prevent SQL injection
        $searchValue = '%' . addcslashes($value, '%_\\') . '%';
        $query->where(function ($q) use ($searchValue) {
            $q->where('first_name', 'like', $searchValue)
              ->orWhere('last_name', 'like', $searchValue)
              ->orWhere('email', 'like', $searchValue)
              ->orWhere('phone', 'like', $searchValue);
        });

        $results = $query->get([
            'id as record_id',
            DB::raw("CONCAT(first_name, ' ', last_name) as label"),
            'email as search_text',
            'role',
            'is_active'
        ]);

    } else {

		// Sanitize search value to prevent SQL injection
		$searchValue = '%' . addcslashes($value, '%_\\') . '%';
		
		$results = GlobalSearchIndex::query()
			->where('module_name', $module)
			->where('organization_id', $org_id)
			->where('deleted', 0)
			->where('label', 'like', $searchValue)
			->get(['module_name', 'record_id', 'label', 'search_text']);
		 }
		$fields = FieldModelManager::make($module,'DetailView',true)->getApiFormFields();

		return $this->success(['fields' => $fields ,  'values'=>  $results]);
	}

	public function globalSearch(Request $request)
{
    // Support both formats: ?value=Su or ?data[value]=Su
    $value = $request->query('value')
        ?? ($request->get('data')['value'] ?? null);
    $user   = auth()->user();
    $org_id = $user->organization_id;

    if (empty($value)) {
        return $this->success(['results' => []]);
    }

    $permissionService = new PermissionService($user);

    // Validate role & profile for non-admin users
    if ($user->is_admin !== 1) {
        $roleId = $user->roles()
            ->orderBy('priority', 'desc')
            ->value('id');

        if (!$roleId) {
            return $this->error('User does not have a valid role assigned');
        }

        $roleProfileRel = DB::table('role_profile_rel')
            ->where('role_id', $roleId)
            ->where('organization_id', $org_id)
            ->first();

        if (!$roleProfileRel || !$roleProfileRel->profile_id) {
            return $this->error('User does not have a valid profile assigned');
        }
    }

    // Get all entity modules
    $allModules = ModuleService::getEntityModules();
    // Filter modules by view permission
    $allowedModules = [];
    foreach ($allModules as $module) {
        if ($permissionService->hasPermission($module, 'view')) {
            $allowedModules[] = $module;
        }
    }

    if (empty($allowedModules)) {
        return $this->success(['results' => []]);
    }

    // Sanitize search value
    $searchValue = '%' . addcslashes($value, '%_\\') . '%';

    // Fetch global search results
    $results = GlobalSearchIndex::query()
        ->where('organization_id', $org_id)
        ->whereIn('module_name', $allowedModules)
        ->where('deleted', 0)
        ->where(function ($query) use ($searchValue) {
            $query->where('label', 'like', $searchValue)
                  ->orWhere('search_text', 'like', $searchValue);
        })
        ->orderBy('module_name')
        ->orderBy('created_at', 'desc')
        ->get()
        ->groupBy('module_name');

    // Transform results
    $transformedResults = [];

    foreach ($results as $module => $records) {

        // Final permission check
        if (!$permissionService->hasPermission($module, 'view')) {
            continue;
        }

        /**
         * 🚨 CRITICAL FIX
         * Skip non-entity / invalid modules (kebab-case breaks PSR-4)
         */
        if (str_contains($module, '-')) {
            continue;
        }

        try {
            $fields = FieldModelManager::make(
                $module,
                'DetailView',
                true
            )->getApiFormFields();
        } catch (\Throwable $e) {
            // Fail-safe: never break global search
            continue;
        }

        $transformedResults[$module] = [
            'fields' => $fields,
            'values' => $records->map(function ($record) {
                return [
                    'id'          => $record->record_id,
                    'label'       => $record->label,
                    'search_text' => $record->search_text,
                    'created_at'  => $record->created_at,
                ];
            })->values()
        ];
    }

    return $this->success(['results' => $transformedResults]);
}

}
