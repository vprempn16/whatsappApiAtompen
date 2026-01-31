<?php

namespace App\Services;

use DB;
use File;
use App\Models\SystemAction;

class ProfileDataGeneratorService
{
    /** @var array<int, string> Cached once per request/job to avoid N queries when regenerating many profiles */
    private ?array $actionsMap = null;

    /**
     * @param object|null $profileRow Optional: object with name, description, status, organization_id to skip profile fetch (caller already has it).
     */
    public function generate(string|int $profileId, string $organizationId, ?object $profileRow = null): array
    {
        if ($profileRow === null) {
            $profileDetails = DB::table('profiles')
                ->where('id', $profileId)
                ->select('id', 'name', 'description', 'status', 'organization_id')
                ->first();
        } else {
            $profileDetails = $profileRow;
        }

        if (!$profileDetails) {
            return [];
        }

        // Ensure profile belongs to this organization (prevent cross-tenant cache write)
        if ((string) ($profileDetails->organization_id ?? '') !== (string) $organizationId) {
            return [];
        }

        $profileArray = [
            'name'        => $profileDetails->name,
            'description' => $profileDetails->description,
            'status'      => $profileDetails->status,
            'modules'     => [],
        ];

        if ($this->actionsMap === null) {
            $this->actionsMap = SystemAction::pluck('action_key', 'id')->toArray();
        }

        // ACTION PERMISSIONS
        $actionRows = DB::table('profile_module_actions')
            ->where('profileid', $profileId)
            ->get();

        foreach ($actionRows as $row) {
            $actionKey = $this->actionsMap[$row->action_id] ?? ('action_'.$row->action_id);

            $profileArray['modules'][$row->modulename]['permissions'][$actionKey]
                = (int) $row->permission;
        }

        // FIELD PERMISSIONS
        $fieldRows = DB::table('profile_module_fields')
            ->where('profileid', $profileId)
            ->get();

        foreach ($fieldRows as $fr) {
            $profileArray['modules'][$fr->modulename]['fields'][$fr->field_id] = [
                'invisible' => (int) $fr->invisible,
                'readonly'  => (int) $fr->readonly,
                'editable'  => (int) $fr->editable,
            ];
        }

        // Save to file
        $path = "Profiles/{$organizationId}/{$profileId}_Profile.php";
        $filePath = base_path($path);

        if (!File::exists(dirname($filePath))) {
            File::makeDirectory(dirname($filePath), 0755, true);
        }

        File::put(
            $filePath,
            "<?php\n\nreturn " . var_export($profileArray, true) . ";\n"
        );

        return $profileArray;
    }
}
