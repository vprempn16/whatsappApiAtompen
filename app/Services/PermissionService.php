<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    protected User $user;
    protected ?array $profileData = null;

    public function __construct(User $user)
    {
        $this->user = $user;

        if ($this->user->is_admin === 1) {
            // Admin bypass: no profile data needed
            $this->profileData = null;
        } else {
            $this->loadProfileData();
        }
    }

    protected function loadProfileData(): void
    {
        $orgId = $this->user->organization_id;
         $roleId = DB::table('roles')
    ->join('role_user_rel', 'roles.id', '=', 'role_user_rel.role_id')
    ->where('role_user_rel.user_id', $this->user->id)
    ->where('role_user_rel.organization_id', $orgId)
    ->where('roles.deleted', 0)
    ->where('roles.status', 'Active')
    ->latest('roles.created_at')
    ->value('roles.id');

      //  dd($this->user->id,$roleId);
        $profileId = null;

        // Load profile id from role_profile_rel (avoid DB calls here ideally, but 
        // kept minimal DB logic for setup; in production this should be cached)
        $roleProfileRel = DB::table('role_profile_rel')
            ->where('role_id', $roleId)
            ->where('organization_id', $orgId)
            ->first();

        if ($roleProfileRel) {
            $profileId = $roleProfileRel->profile_id;
        }

        if (!$profileId) {
            $this->profileData = null;
            return;
        }
        $profileFile = base_path("Profiles/{$orgId}/{$profileId}_Profile.php");
        if (!file_exists($profileFile)) {
            $this->profileData = null;
            return;
        }
        $data = include $profileFile;
        // Validate required keys to fail safely
        if (
            !is_array($data) ||
            !isset($data['modules']) ||
            !is_array($data['modules'])
        ) {
            $this->profileData = null;
            return;
        }

        $this->profileData = $data;
    }

    /**
     * ------------------------------------------------------------
     * MODULE PERMISSION
     * ------------------------------------------------------------
     */
    public function hasPermission(string $module, string $actionKey = 'view'): bool
    {
        if ($this->user->is_admin === 1) {
            return true;
        }

        if (!$this->profileData) {
            return false;
        }

        if (!isset(
            $this->profileData['modules'][$module]['permissions'][$actionKey]
        )) {
            return false;
        }

        return $this->profileData['modules'][$module]['permissions'][$actionKey] === 1;
    }

    /**
     * ------------------------------------------------------------
     * FIELD PERMISSION
     * ------------------------------------------------------------
     */
    public function canViewField(string $module, string $fieldId): bool
    {
        if ($this->user->is_admin === 1) {
            return true;
        }

        if (!$this->profileData) {
            return false;
        }

        $fieldSettings = $this->profileData['modules'][$module]['fields'][$fieldId] ?? null;

        if (!$fieldSettings) {
            return false;
        }
if ($fieldId === 'id') {
    return true;
}

        // Field is visible if invisible=0
        return (int) ($fieldSettings['invisible'] ?? 1) === 0;
    }

    public function canWriteField(string $module, string $fieldId): bool
    {
        if ($this->user->is_admin === 1) {
            return true;
        }

        if (!$this->profileData) {
            return false;
        }

        $fieldSettings = $this->profileData['modules'][$module]['fields'][$fieldId] ?? null;

        if (!$fieldSettings) {
            return false;
        }
if ($fieldId === 'id') {
    return true;
}

        // Field is writable if editable=1 and invisible=0
        if ((int) ($fieldSettings['invisible'] ?? 1) === 1) {
            return false;
        }

        return (int) ($fieldSettings['editable'] ?? 0) === 1;
    }
}