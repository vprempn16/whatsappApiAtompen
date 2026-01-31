<?php


namespace App\Services;


use Illuminate\Support\Facades\DB;
use App\Models\SystemAction;
use App\Models\ProfileModuleAction;
use App\Models\ProfileModuleField; // optional if you have it
use App\Models\FieldModelManager;


class ProfileSaveService
{
protected $profileId;
protected $user;


public function __construct($profileId, $user)
{
$this->profileId = $profileId;
$this->user = $user;
}


/**
* Persist unified permissions.
* $permissions = [ 'Contact' => ['view'=>1,'create'=>1,'export'=>0,...], ... ]
* $fields = [ 'Contact' => [ 'field_uuid' => ['invisible'=>1,...], ... ] ]
*/
public function saveUnified(array $permissions = [], array $fields = [])
{
// load action map (key => id)
$actionMap = SystemAction::pluck('id', 'action_key')->toArray();


// clear old module-action perms
DB::table('profile_module_actions')->where('profileid', $this->profileId)->delete();

$now = now();
$actionRows = [];
foreach ($permissions as $module => $actions) {
    foreach ($actions as $actionKey => $value) {
        if (!isset($actionMap[$actionKey])) continue;
        $actionRows[] = [
            'profileid' => $this->profileId,
            'organization_id' => $this->user->organization_id,
            'modulename' => $module,
            'action_id' => $actionMap[$actionKey],
            'permission' => (int) $value,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
if (!empty($actionRows)) {
    DB::table('profile_module_actions')->insert($actionRows);
}

// fields
if (!empty($fields)) {
    DB::table('profile_module_fields')->where('profileid', $this->profileId)->delete();

    $fieldRows = [];
    foreach ($fields as $module => $moduleFields) {
        foreach ($moduleFields as $fieldKey => $perms) {
            $fieldId = $this->isUuid($fieldKey) ? $fieldKey : FieldModelManager::getFieldId($module, $fieldKey, $this->user->organization_id ?? null);
            if (!$fieldId) continue;

            $fieldRows[] = [
                'profileid' => $this->profileId,
                'organization_id' => $this->user->organization_id,
                'modulename' => $module,
                'field_id' => $fieldId,
                'invisible' => (int) ($perms['invisible'] ?? 0),
                'readonly' => (int) ($perms['readonly'] ?? 0),
                'editable' => (int) ($perms['editable'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
    }
    if (!empty($fieldRows)) {
        DB::table('profile_module_fields')->insert($fieldRows);
    }
}
}


protected function isUuid($v)
{
return (bool) preg_match('/^[0-9a-fA-F\\-]{36}$/', $v);
}
}
