<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ApiController;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Services\ProfileSaveService;
use App\Http\Filters\V1\ProfileFilter;

use App\Models\SystemAction;
use App\Models\ProfileModuleAction;
use App\Models\ProfileModuleField;
use App\Models\FieldModelManager;
use App\Services\ModuleService;
use App\Jobs\RepairProfilesJob;

class ProfileController extends ApiController
{
    public function profileModuleFields($module = null)
    {
        if (!$module) {
            return $this->error('Module name is required');
        }
        $viewType = 'ListView';
        $info = FieldModelManager::make($module, $viewType, false)->getApiFormFields($module);
        return $this->success([
            'fields' => $info,
        ]);
    }

    public function index(ProfileFilter $filters)
    {
        $perPage = (int) request()->query('per_page', 20);
        $perPage = max(1, min(100, $perPage));
        $paginator = Profile::filter($filters)
            ->where('organization_id', auth()->user()->organization_id)
            ->where('deleted', 0)
            ->paginate($perPage);

        $profiles = \App\Http\Resources\V1\ProfileResource::collection($paginator->items());

        return $this->success([
            'profiles' => $profiles,
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
        ]);
    }
public function portalModules()
{
   $modules = ModuleService::getEntityModules();

    return $this->success([
        'modules' => $modules
    ]);
}

    /**
     * SAVE PROFILE + unified permissions
     * Frontend JSON must send:
     *
     * {
     *   "data": {
     *      "profile": {...},
     *      "permissions": {
     *          "Contact": { "view":1, "create":1, "edit":0, "delete":1, "export":1 }
     *      },
     *      "fields": {
     *          "Contact": {
     *             "firstname": { invisible:1, readonly:0, editable:1 }
     *          }
     *      }
     *   }
     * }
     */
    public function saveAll(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = auth()->user();

            // profile basic info
            $data = $request->input('data', []);

// 🔁 Normalize incoming payload
$profileData = [
    'id'          => $data['profile']['id'] ?? null,
    'name'        => $data['profile']['name'] ?? null,
    'description' => $data['profile']['description'] ?? null,
    'status'      => $data['profile']['status'] ?? null,
];

// Extract permissions + fields from modules
$permissions = [];
$fieldsGrouped = [];

foreach ($data['modules'] ?? [] as $module => $moduleData) {

    // actions
    if (isset($moduleData['permissions']['actions'])) {
        $permissions[$module] = $moduleData['permissions']['actions'];
    }

    // fields
    if (isset($moduleData['fields'])) {
        $fieldsGrouped[$module] = $moduleData['fields'];
    }
}


$id = $profileData['id'] ?? null;
if ($id === 'new' || empty($id)) {
    $id = null;
}

            // When updating by id, ensure profile belongs to current organization (prevent cross-tenant overwrite)
            if (!empty($id)) {
                $existing = Profile::where('id', $id)->first();
                if ($existing && (string) $existing->organization_id !== (string) $user->organization_id) {
                    DB::rollBack();
                    return $this->error('Profile not found or access denied');
                }
            }

            // Generate ID for new profiles (lock to avoid race when two admins create at once)
            if (empty($id)) {
                $maxId = Profile::lockForUpdate()->max('id');
                $id = ($maxId ?? 0) + 1;
            }

            // Create or update profile
            $profile = Profile::updateOrCreate(
                ['id' => $id],
                [
                    'name'            => $profileData['name'] ?? null,
                    'description'     => $profileData['description'] ?? null,
                    'status'          => $profileData['status'] ?? null,
                    'organization_id' => $user->organization_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // VALIDATE FIELD PERMISSIONS BEFORE SAVE
            $this->validateProfileFields($fieldsGrouped);
            // Save unified action + field permissions
            $service = new ProfileSaveService($profile->id, $user);
            $service->saveUnified($permissions, $fieldsGrouped);

            // Rebuild profile cache file
            $this->generateProfileData($profile->id);

            DB::commit();

            return $this->success([
                'message' => 'Profile & permissions saved successfully',
                'profile' => new \App\Http\Resources\V1\ProfileResource($profile),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to save profile: ' . $e->getMessage());
        }
    }

    private function validateProfileFields(array $fieldsGrouped)
{
    $viewType = 'ListView';

    foreach ($fieldsGrouped as $moduleName => $fields) {
        // 2️⃣ Get allowed fields for the module from CRM
        $allowedFields = FieldModelManager::make($moduleName, $viewType , false)->getApiFormFields();
        $allowedApiFields = collect($allowedFields)
            ->pluck('fieldname')
            ->filter()
            ->toArray();
        // 3️⃣ Validate each field and its permissions
        foreach ($fields as $apiField => $permissions) {
            if (!in_array($apiField, $allowedApiFields, true)) {
                throw new \Exception(
                    "Field '{$apiField}' is not valid for module '{$moduleName}' in CRM. Allowed fields: [" .
                    implode(', ', $allowedApiFields) . "]"
                );
            }

            // 4️⃣ Validate permission keys and values as per CRM rules
            $allowedKeys = ['invisible', 'readonly', 'editable'];
            foreach ($permissions as $key => $value) {
                if (!in_array($key, $allowedKeys, true)) {
                    throw new \Exception("Invalid permission key '{$key}' for field '{$apiField}' in module '{$moduleName}'.");
                }
                if (!in_array((int)$value, [0, 1], true)) {
                    throw new \Exception("Invalid permission value for '{$apiField}.{$key}' in module '{$moduleName}'.");
                }
            }
        }
    }
}

    /**
     * DETAILS API (RETURN UNIFIED PERMISSIONS)
     *
     * Returns:
     *  - profile info
     *  - permissions (module → action → 0/1)
     *  - fields (module → apiField → perms)
     */
    public function details($id)
    {
        try {
            // 🆕 CASE: New Profile
        if ($id === 'new') {
            return $this->success([
                'id' => '',
                    'name'        => '',
                    'description' => '',
                    'status'      => '',
                    'modules'     => []
            ]);
        }

            $orgId = auth()->user()->organization_id;
            $profile = Profile::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->firstOrFail();

            $profileInfo = [
                'id'            => $profile->id,
                'name'          => $profile->name,
                'description'   => $profile->description,
                'status'        => $profile->status,
                'organization'  => $profile->organization_id,
            ];

            // load action map: id → action_key
            $actionMap = SystemAction::pluck('action_key', 'id')->toArray();

            // module → actionKey → permission
            $permissions = [];
            $rows = ProfileModuleAction::where('profileid', $id)->get();

            foreach ($rows as $row) {
                $actionKey = $actionMap[$row->action_id] ?? ('action_'.$row->action_id);
                $permissions[$row->modulename][$actionKey] = (int)$row->permission;
            }

            // FIELD PERMISSIONS (bulk resolve field_id → api name to avoid N+1)
            $fieldsGrouped = [];
            $fieldRows = ProfileModuleField::where('profileid', $id)->get();
            $fieldIds = $fieldRows->pluck('field_id')->unique()->values()->all();
            $apiNameMap = FieldModelManager::getApiFieldNames($fieldIds);

            foreach ($fieldRows as $f) {
                $apiName = $apiNameMap[$f->field_id] ?? $f->field_id;

                $fieldsGrouped[$f->modulename][$apiName] = [
                    'invisible'  => (int)$f->invisible,
                    'readonly' => (int)$f->readonly,
                    'editable' => (int)$f->editable,
                ];
            }

            $modules = [];
            foreach ($permissions as $moduleName => $perm) {
                $modules[$moduleName] = [
                    'permissions' => [
                        //'module' => $perm['module'] ?? null,
                        'actions' => $perm['actions'] ?? $perm, // adapt if your structure differs
                    ],
                    'fields' => $fieldsGrouped[$moduleName] ?? [],
                ];
            }

            $response = [
                'id' => $profileInfo['id'],
                'name' => $profileInfo['name'] ?? "",
                'description' => $profileInfo['description'] ?? "",
                'status' => $profileInfo['status'] ?? "",
                'organization' => $profileInfo['organization'] ?? null,
                'modules' => $modules ?? []
            ];
            return $this->success($response);

        } catch (\Exception $e) {
            return $this->error("Failed to load profile details: " . $e->getMessage());
        }
    }

    /**
     * CACHE GENERATOR (UPDATED FOR NEW TABLES)
     * Builds: Profiles/{org}/{profileId}_Profile.php
     */
    public function generateProfileData($profileId)
{
    return app(\App\Services\ProfileDataGeneratorService::class)
        ->generate($profileId, auth()->user()->organization_id);
}

    private function arrayToString($array, $indent = 1)
    {
        $output = '';
        $prefix = str_repeat('    ', $indent);

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $output .= "{$prefix}'{$key}' => array (\n" .
                    $this->arrayToString($value, $indent + 1) .
                    "{$prefix}),\n";
            } else {
                $output .= "{$prefix}'{$key}' => " . var_export($value, true) . ",\n";
            }
        }

        return $output;
    }
    public function delete($id)
{
    DB::beginTransaction();

    try {
        // Prevent deleting "new"
        if ($id === 'new') {
            return $this->error('Invalid profile ID');
        }

        $orgId = auth()->user()->organization_id;
        $profile = Profile::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$profile) {
            return $this->error('Profile not found or already deleted');
        }

        // Soft delete profile
        $profile->update([
            'deleted' => 1,
            'status'  => 0
        ]);

        // Clear permissions
        DB::table('profile_module_actions')
            ->where('profileid', $id)
            ->delete();

        DB::table('profile_module_fields')
            ->where('profileid', $id)
            ->delete();

        // Remove role assignments so no role references this profile
        DB::table('role_profile_rel')
            ->where('profile_id', $id)
            ->where('organization_id', $orgId)
            ->delete();

        // OPTIONAL: remove cache file
        $path = "Profiles/" . auth()->user()->organization_id . "/{$id}_Profile.php";
        $filePath = base_path($path);

        if (File::exists($filePath)) {
            File::delete($filePath);
        }

        DB::commit();

        return $this->success([
            'message' => 'Profile deleted successfully'
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return $this->error('Failed to delete profile: ' . $e->getMessage());
    }
}

    /**
     * Repair/Refresh endpoint - Regenerates profile data cache.
     *
     * Route: POST /api/v1/settings/profile/repair
     *
     * Request Body (optional):
     * {
     *   "type": "profile|all",
     *   "profile_id": "uuid" // optional, only for profile type (single profile)
     * }
     */
    public function repair(Request $request)
    {
        try {
            $user = auth()->user();
            $type = $request->input('type', 'all'); // 'profile' or 'all'
            $profileId = $request->input('profile_id', null);
            $orgId = $user->organization_id;

            // Queue "repair all" or "regenerate all profiles" (no profile_id) to avoid long request
            $runInQueue = ($type === 'all') || ($type === 'profile' && empty($profileId));
            if ($runInQueue) {
                RepairProfilesJob::dispatch($orgId, $type, $profileId);
                return $this->success([
                    'message' => 'Repair queued',
                    'queued' => true,
                ]);
            }

            $results = ['profiles' => []];

            // Regenerate profile data cache (sync: single profile only; "all" is queued above)
            if ($type === 'profile' && $profileId) {
                $profile = Profile::where('id', $profileId)
                    ->where('organization_id', $orgId)
                    ->where('deleted', 0)
                    ->first();

                if ($profile) {
                    try {
                        $this->generateProfileData($profileId);
                        $results['profiles'][$profileId] = [
                            'id' => $profileId,
                            'name' => $profile->name,
                            'status' => 'regenerated'
                        ];
                    } catch (\Exception $e) {
                        $results['profiles'][$profileId] = [
                            'id' => $profileId,
                            'name' => $profile->name ?? 'Unknown',
                            'status' => 'error',
                            'error' => $e->getMessage()
                        ];
                    }
                } else {
                    $results['profiles'][$profileId] = [
                        'id' => $profileId,
                        'status' => 'not_found'
                    ];
                }
            }

            $message = $type === 'profile' ? 'Profile data cache regenerated' : 'Repair completed';

            return $this->success([
                'message' => $message,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            return $this->error('Repair failed: ' . $e->getMessage());
        }
    }

}