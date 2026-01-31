<?php

namespace App\Modules\Api\V1\User\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use App\Modules\Api\V1\User\Resources\UserResource;
use App\Services\CRM\RecordObject;
use Illuminate\Support\Facades\DB;


class UserController extends ApiController
{
    public function show(string $id)
{
    try {
        $orgId = auth()->user()->organization_id;
        
        $user = User::where('id', $id)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$user) {
            return $this->error('User not found');
        }

        // Get assigned role IDs
        $roleIds = DB::table('role_user_rel')
            ->where('user_id', $user->id)
            ->pluck('role_id')
            ->toArray() ?? [];
        return $this->success([
                'values' => [
                    'firstName'       => $user->first_name,
                    'lastName'        => $user->last_name,
                    'email'           => $user->email,
                    'phoneNumber'     => $user->phone,
                    'organizationId'  => $user->organization_id,
                    'roleId'          => $roleIds
            ]
        ]);

    } catch (\Throwable $e) {
        return $this->error($e->getMessage());
    }
}
    public function store(Request $request, string $id = "new")
{
    try {
        $payload = $request->input('data.values', []);

        if (empty($payload)) {
            return $this->error('No data received');
        }
        
        // Strict org validation: only current organization. Never trust payload.
        $orgId   = $payload['organizationId'] ?? auth()->user()->organization_id ;
        if(!$orgId) {
            return $this->error('No organization set for current user');
        }
        $roleIds = $payload['roleId'] ?? [];

        // Ensure all role IDs belong to current organization
        if (!empty($roleIds)) {
            $validRoleCount = DB::table('roles')
                ->whereIn('id', $roleIds)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->count();
            if ($validRoleCount !== count((array) $roleIds)) {
                return $this->error('One or more roles are invalid or do not belong to your organization');
            }
        }

        DB::beginTransaction();

// 🔥 FIX: map confirmPassword → password_confirmation
if (isset($payload['confirmPassword'])) {
    $payload['password_confirmation'] = $payload['confirmPassword'];
}

$rules = [
    'firstName' => ['required', 'string', 'max:100'],
    'lastName'  => ['nullable', 'string', 'max:100'],
    'email'     => [
        'required',
        'email',
        'max:150',
        'unique:users,email,' . ($id ?? 'NULL') . ',id'
    ],
    'phoneNumber' => ['nullable', 'string', 'max:20'],

    // 🔐 password required only on create
    'password' => [$id ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],

    // ✅ role is optional now
    'roleId'   => ['nullable', 'array', 'min:1'],
    'roleId.*' => ['integer', 'exists:roles,id'],

    // organizationId removed from validation - always uses authenticated user's org
];


validator($payload, $rules)->validate();

        /* ================= UPDATE ================= */

        if ($id !== "new" && !empty($id)) {
            $user = User::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->first();

            if (!$user) {
                return $this->error("User not found");
            }

            $user->first_name = $payload['firstName'];
            $user->last_name  = $payload['lastName'] ?? null;
            $user->email      = $payload['email'];
            $user->phone      = $payload['phoneNumber'] ?? null;

            if (!empty($payload['password'])) {
                $user->password = Hash::make($payload['password']);
            }

            $user->save();

            // 🔁 Sync roles
            DB::table('role_user_rel')->where('user_id', $user->id)->delete();

            foreach ($roleIds as $roleId) {
                DB::table('role_user_rel')->insert([
                    'user_id' => $user->id,
                    'organization_id' => $orgId,
                    'role_id' => $roleId]);
            }

            DB::commit();

            $user->refresh();
            return $this->success(new UserResource($user), 'User updated successfully');
        }

        /* ================= CREATE ================= */

        $user = new User();
        $user->id              = (string) Str::uuid();
        $user->first_name      = $payload['firstName'];
        $user->last_name       = $payload['lastName'] ?? null;
        $user->email           = $payload['email'];
        $user->phone           = $payload['phoneNumber'] ?? null;
        $user->password        = Hash::make($payload['password']);
        $user->organization_id = $orgId;
        $user->is_active       = 1;
        /* 🔥 FIRST USER = ADMIN (NO OTHER CHANGE) */
$isFirstUser = !User::where('organization_id', $orgId)
    ->where('deleted', 0)
    ->exists();

if ($isFirstUser) {
    $user->is_admin = 1;
}
/* 🔥 END PATCH */

        $user->save();

        // 🔗 Attach roles
        foreach ($roleIds as $roleId) {
            DB::table('role_user_rel')->insert([
                'user_id' => $user->id,
                'organization_id' => $orgId,
                'role_id' => $roleId
            ]);
        }

        DB::commit();

        $user->refresh();
        
        // Note: Token generation removed for admin-created users for security
        // Users should login to get their own token
        return $this->success([
            'user' => new UserResource($user),
        ], 'User created successfully');

    } catch (ValidationException $e) {
        DB::rollBack();
        return $this->error($e->errors());
    } catch (\Throwable $e) {
        DB::rollBack();
        return $this->error($e->getMessage());
    }
}

    public function index(Request $request)
{
    $organizationId = auth()->user()->organization_id;

    if (!$organizationId) {
        return $this->error('No organization set for current user');
    }

    $fieldManager = \App\Models\FieldModelManager::make(
        'User',
        'ListView',
        false
    );

    $query = \App\Models\User::where('organization_id', $organizationId)
        ->where('deleted', 0);

    $perPage = (int) $request->query('per_page', 20);
    $users   = $query->paginate($perPage);

    // Load role IDs for all users (efficient batch load)
    $userIds = collect($users->items())->pluck('id')->toArray();
    $roleIdsMap = [];
    
    if (!empty($userIds)) {
        $roleIds = DB::table('role_user_rel')
            ->whereIn('user_id', $userIds)
            ->select('user_id', 'role_id')
            ->get()
            ->groupBy('user_id')
            ->map(function ($roles) {
                return $roles->pluck('role_id')->map(fn($id) => (int)$id)->toArray();
            })
            ->toArray();
        
        $roleIdsMap = $roleIds;
    }

    // Pass role IDs map to resource collection
    UserResource::$roleIdsMap = $roleIdsMap;

    return $this->success([
        'fields' => $fieldManager->getApiFormFields(),
        'list'   => UserResource::collection($users)->items(),
        'meta'   => [
            'current_page' => $users->currentPage(),
            'last_page'    => $users->lastPage(),
            'per_page'     => $users->perPage(),
            'total'        => $users->total(),
        ],
        'links'  => [
            'first' => $users->url(1),
            'last'  => $users->url($users->lastPage()),
            'prev'  => $users->previousPageUrl(),
            'next'  => $users->nextPageUrl(),
        ],
    ]);
}
public function destroy(string $id)
    {
        try {
            $orgId = auth()->user()->organization_id;
            // Strict org validation: only allow delete for users in current organization
            $user = User::where('id', $id)
                ->where('organization_id', $orgId)
                ->where('deleted', 0)
                ->first();

            if (!$user) {
                return $this->error('User not found');
            }

            $record = RecordObject::make('User', $id);
            $record->deleteRecord();

            return $this->success([]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }
}
