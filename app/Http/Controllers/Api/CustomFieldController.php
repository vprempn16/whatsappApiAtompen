<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;

use App\Http\Controllers\ApiController;
use App\Models\CrmField;
use App\Models\Profile;
use App\Models\ProfileModuleField;
use App\Models\FieldModelManager;
use App\Services\CrmFieldService;
use App\Traits\ResultTrait;
use App\Modules\Api\V1\Zapier\Services\ZapierFieldMapperService;

class CustomFieldController extends ApiController
{
    use ResultTrait;

    /**
     * CREATE / UPDATE CUSTOM FIELD
     */
    public function create(Request $request)
{
    DB::beginTransaction();

    try {
        $data = $request->input('data', []);

        /* ---------------- VALIDATION ---------------- */
        $data = validator($data, [
            'id'                    => 'nullable|string',
            'fieldlabel'            => 'required|string|max:150',
            'fieldtype'             => 'required|string',
            'modulename'            => 'required|string',
            'mandatory'             => 'nullable|in:0,1',
            'profiles'              => 'array',
            'profiles.*.id'         => 'required|integer',
            'profiles.*.invisible'  => 'in:0,1',
            'profiles.*.editable'   => 'in:0,1',
            'profiles.*.readonly'   => 'in:0,1',
            'options'               => 'array',
            'options.*.label'       => 'required|string|max:100',
            'options.*.value'       => 'nullable|string|max:100',
        ])->validate();

        $updatedProfileIds = collect($data['profiles'] ?? [])
    ->pluck('id')
    ->map(fn ($id) => (int) $id)
    ->unique()
    ->values()
    ->toArray();

        $allowedTypes = [
            'text', 'textarea', 'number', 'email',
            'date', 'datetime', 'picklist',
            'multiselect', 'checkbox',
        ];

        if (!in_array($data['fieldtype'], $allowedTypes, true)) {
            DB::rollBack();
            return $this->error('Invalid field type');
        }

        $organizationId = auth()->user()->organization_id;

        // Ensure all profile IDs belong to current organization (prevent cross-tenant link)
        if (!empty($updatedProfileIds)) {
            $validCount = Profile::whereIn('id', $updatedProfileIds)
                ->where('organization_id', $organizationId)
                ->where('deleted', 0)
                ->count();
            if ($validCount !== count($updatedProfileIds)) {
                DB::rollBack();
                return $this->error('One or more profile IDs are invalid or do not belong to your organization');
            }
        }

        // Strict validation for module name
        $module = preg_replace('/[^a-zA-Z0-9]/', '', $data['modulename']);

        if (empty($module) || strlen($module) > 50) {
            DB::rollBack();
            return $this->error('Invalid module name. Must be alphanumeric and max 50 characters.');
        }

        $module = Str::snake($module);

        if (strlen($module) > 47) {
            DB::rollBack();
            return $this->error('Module name too long for table creation.');
        }

        $customTable = "l{$module}_custom_values";

        if (preg_match('/[^a-z0-9_]/', $customTable)) {
            DB::rollBack();
            return $this->error('Invalid table name format.');
        }

        $existingCustomTables = DB::table('crm_fields')
            ->where('organization_id', $organizationId)
            ->where('is_custom_field', 1)
            ->where('deleted', 0)
            ->distinct('tablename')
            ->count('tablename');

        if ($existingCustomTables >= 100) {
            DB::rollBack();
            return $this->error('Maximum number of custom field tables reached for this organization.');
        }

        /* =====================================================
           UPDATE EXISTING FIELD
        ===================================================== */
        if (!empty($data['id']) && $data['id'] !== 'new') {

            $field = CrmField::where('id', $data['id'])
                ->where('deleted', 0)
                ->where(function ($q) use ($organizationId) {
                    $q->where(function ($qq) use ($organizationId) {
                        $qq->where('is_custom_field', 1)
                           ->where('organization_id', $organizationId);
                    })->orWhere('is_custom_field', 0);
                })
                ->first();

            if (!$field) {
                DB::rollBack();
                return $this->error('Field not found');
            }

            // STEP 1️⃣ Collect incoming profile IDs
            $updatedProfileIds = collect($data['profiles'] ?? [])
    ->pluck('id')
    ->map(fn ($id) => (int) $id)
    ->unique()
    ->values()
    ->toArray();


            $incomingProfileIds = $updatedProfileIds;

            // STEP 2️⃣ Remove removed profiles
            ProfileModuleField::where('field_id', $field->id)
                ->where('modulename', $data['modulename'])
                ->where('organization_id', $organizationId)
                ->when(!empty($incomingProfileIds), function ($q) use ($incomingProfileIds) {
                    $q->whereNotIn('profileid', $incomingProfileIds);
                })
                ->delete();

            // STEP 3️⃣ Insert / Update profiles
            if (!empty($data['profiles'])) {
                foreach ($data['profiles'] as $profilePerm) {
                    ProfileModuleField::updateOrCreate(
                        [
                            'profileid'  => (int) $profilePerm['id'],
                            'modulename' => $data['modulename'],
                            'field_id'   => $field->id,
                        ],
                        [
                            'organization_id' => $organizationId,
                            'invisible'       => (int) ($profilePerm['invisible'] ?? 1),
                            'editable'        => (int) ($profilePerm['editable'] ?? 1),
                            'readonly'        => (int) ($profilePerm['readonly'] ?? 0),
                        ]
                    );
                }
            }

            /* ---- FIELD META UPDATE ---- */
            if ((int) $field->is_custom_field === 1) {

                $field->update([
                    'fieldlabel' => $data['fieldlabel'],
                    'mandatory'  => $data['mandatory'] ?? 0,
                ]);

            } else {

                DB::table('crm_default_field_definitions')->updateOrInsert(
                    [
                        'organization_id' => $organizationId,
                        'modulename'      => $field->modulename,
                        'fieldname'       => $field->fieldname,
                    ],
                    [
                        'fieldlabel' => $data['fieldlabel'],
                        'mandatory'  => $data['mandatory'] ?? 0,
                        'seq'        => $field->seq,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            /* ---- PICKLIST OPTIONS UPDATE ---- */
            if (
                (int) $field->is_custom_field === 1 &&
                in_array($field->fieldtype, ['picklist', 'multiselect'], true)
            ) {

                if (empty($data['options'])) {
                    DB::rollBack();
                    return $this->error('Options are required for picklist or multiselect fields');
                }

                foreach ($data['options'] as $option) {
                    $value = Str::slug($option['value'] ?? $option['label'], '_');

                    $exists = DB::table('picklist_values')
                        ->where('field_id', $field->id)
                        ->where('value', $value)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $sort = DB::table('picklist_values')
                        ->where('field_id', $field->id)
                        ->max('sort_order') ?? 0;

                    DB::table('picklist_values')->insert([
                        'id'         => (string) Str::uuid(),
                        'field_id'   => $field->id,
                        'label'      => $option['label'],
                        'value'      => $value,
                        'sort_order' => $sort + 1,
                        'status'     => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::commit();

            dispatch(function () use ($updatedProfileIds, $organizationId) {
                $generator = app(\App\Services\ProfileDataGeneratorService::class);

foreach ($updatedProfileIds as $profileId) {
    $generator->generate($profileId, $organizationId);
}

            });

            return $this->success([
                'message'  => 'Field updated successfully',
                'field_id' => $field->id,
            ]);
        }

        /* =====================================================
           CREATE NEW FIELD (CUSTOM ONLY)
        ===================================================== */

        if (!Schema::hasTable($customTable)) {
            Schema::create($customTable, function (Blueprint $table) {
                $table->char('id', 36)->primary();
                $table->char('record_id', 36);
                $table->char('organization_id', 36);
                $table->char('field_id', 36);
                $table->text('field_value')->nullable();
                $table->timestamps();
                $table->unique(['record_id', 'field_id']);
            });
        }

        $fieldId   = (string) Str::uuid();
        $fieldname = Str::slug($data['fieldlabel'], '_');

        $exists = CrmField::where('modulename', $data['modulename'])
            ->where('fieldname', $fieldname)
            ->where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->exists();

        if ($exists) {
            DB::rollBack();
            return $this->error(
                "Field '{$data['fieldlabel']}' already exists in module '{$data['modulename']}'."
            );
        }

        $seq = CrmField::where('modulename', $data['modulename'])
            ->where('deleted', 0)
            ->max('seq') ?? 0;

        $field = CrmField::create([
            'id'              => $fieldId,
            'modulename'      => $data['modulename'],
            'fieldname'       => $fieldname,
            'fieldlabel'      => $data['fieldlabel'],
            'fieldtype'       => $data['fieldtype'],
            'tablename'       => $customTable,
            'mandatory'       => $data['mandatory'] ?? 0,
            'apifieldname'    => Str::camel($fieldname),
            'displaytype'     => 1,
            'is_custom_field' => 1,
            'organization_id' => $organizationId,
            'seq'             => $seq + 1,
        ]);

        if (in_array($data['fieldtype'], ['picklist', 'multiselect'], true)) {
            foreach ($data['options'] ?? [] as $i => $opt) {
                DB::table('picklist_values')->insert([
                    'id'         => (string) Str::uuid(),
                    'field_id'   => $fieldId,
                    'label'      => $opt['label'],
                    'value'      => Str::slug($opt['label'], '_'),
                    'sort_order' => $i + 1,
                    'status'     => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        DB::commit();

        $generator = app(\App\Services\ProfileDataGeneratorService::class);

        foreach ($updatedProfileIds as $profileId) {
            $generator->generate($profileId, $organizationId);
        }

        $fieldMapper = app(ZapierFieldMapperService::class);
        $moduleName  = $data['modulename'];
        $zapierModule = strtolower($moduleName);

        if (in_array($zapierModule, ['contact', 'lead', 'product'])) {
            $zapierModule = $zapierModule === 'contact'
                ? 'contacts'
                : ($zapierModule === 'lead' ? 'leads' : 'products');

            $fieldMapper->clearCache($zapierModule, $organizationId);
        }

        return $this->success($field);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('CUSTOM_FIELD_ERROR', ['error' => $e->getMessage()]);
        return $this->error('Failed to create custom field: ' . $e->getMessage());
    }
}

    /* ================= OTHER FUNCTIONS (UNCHANGED LOGIC) ================= */

    public function createViewFields(Request $request)
    {
        $organizationId = auth()->user()->organization_id;

        $fieldTypes = [
            ['value'=>'text','label'=>'Text'],
            ['value'=>'textarea','label'=>'Textarea'],
            ['value'=>'number','label'=>'Number'],
            ['value'=>'email','label'=>'Email'],
            ['value'=>'date','label'=>'Date'],
            ['value'=>'datetime','label'=>'Datetime'],
            ['value'=>'picklist','label'=>'Picklist'],
            ['value'=>'multiselect','label'=>'Multi Select'],
            ['value'=>'checkbox','label'=>'Checkbox'],
        ];

        $profiles = DB::table('profiles')
            ->where('organization_id',$organizationId)
            ->where('deleted',0)
            ->orderBy('name')
            ->get(['id','name'])
            ->map(fn($p)=>[
                'id'=>$p->id,
                'name'=>$p->name,
                'permissions'=>[
                    'invisible'=>0,
                    'editable'=>1,
                    'readonly'=>0,
                ],
            ])->values();

        $fields = [
            ['name'=>'modulename','label'=>'Module','type'=>'text','required'=>true],
            ['name'=>'fieldlabel','label'=>'Field Label','type'=>'text','required'=>true],
            [
                'name'=>'fieldtype',
                'label'=>'Field Type',
                'type'=>'picklist',
                'required'=>true,
                'options'=>$fieldTypes,
            ],
            ['name'=>'mandatory','label'=>'Mandatory','type'=>'checkbox'],
            ['name'=>'profiles','label'=>'Profile Permissions','type'=>'profile_permission'],
            [
                'name'=>'options',
                'label'=>'Options',
                'type'=>'array',
                'showIf'=>['fieldtype'=>['picklist','multiselect']],
            ],
        ];

        return $this->success([
            'fields'=>$fields,
            'profiles'=>$profiles,
        ]);
    }

    public function list(Request $request)
    {
        $module = $request->query('module');
        if (!$module) {
            return $this->error('Module is required');
        }

        $module = preg_replace('/[^a-zA-Z]/','',$module);

        $fields = FieldModelManager::make($module,'DetailView',false)
            ->getApiFormFields();

        return $this->success($fields);
    }

    public function getFields($modulename)
    {
        $organizationId = auth()->user()->organization_id;
        $modulename = preg_replace('/[^a-zA-Z]/','',$modulename);

        $fields = app(CrmFieldService::class)
            ->getFieldsWithOverrides($modulename,$organizationId);

        return $this->success($fields);
    }

    public function show($module, $id)
{
    $organizationId = auth()->user()->organization_id;
    $module = preg_replace('/[^a-zA-Z]/','',$module);

    $field = CrmField::where('id', $id)
        ->where('modulename', $module)
        ->where('deleted', 0)
        ->where(function ($q) use ($organizationId) {
            $q->where('is_custom_field', 0)
              ->orWhere(function ($qq) use ($organizationId) {
                  $qq->where('is_custom_field', 1)
                     ->where('organization_id', $organizationId);
              });
        })
        ->first();

    if (!$field) {
        return $this->error('Field not found');
    }

    /* =====================================================
       APPLY ORG-LEVEL OVERRIDE FOR GLOBAL FIELDS
    ===================================================== */
    if ((int) $field->is_custom_field === 0) {

        $override = DB::table('crm_default_field_definitions')
            ->where('organization_id', $organizationId)
            ->where('modulename', $field->modulename)
            ->where('fieldname', $field->fieldname)
            ->first();

        if ($override) {
            // Override only allowed attributes
            $field->fieldlabel = $override->fieldlabel;
            $field->mandatory  = (int) $override->mandatory;
        }
    }

    $profiles = ProfileModuleField::where('field_id', $field->id)
        ->where('modulename', $module)
        ->where('organization_id', $organizationId)
        ->get(['profileid as id','invisible','editable','readonly'])
        ->map(fn($p) => [
            'id'       => $p->id,
            'invisible'  => (int) $p->invisible,
            'editable' => (int) $p->editable,
            'readonly' => (int) $p->readonly,
        ]);

    $options = [];
    if (in_array($field->fieldtype, ['picklist','multiselect'], true)) {
        $options = DB::table('picklist_values')
            ->where('field_id', $field->id)
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get(['label','value'])
            ->map(fn($o) => [
                'label' => $o->label,
                'value' => $o->value,
            ]);
    }

    return $this->success([
        'id'         => $field->id,
        'fieldlabel'=> $field->fieldlabel,
        'fieldtype' => $field->fieldtype,
        'modulename'=> $field->modulename,
        'mandatory' => (int) $field->mandatory,
        'profiles'  => $profiles,
        'options'   => $options,
    ]);
}
public function delete($id)
{
    DB::beginTransaction();

    try {
        $organizationId = auth()->user()->organization_id;

        $field = CrmField::where('id', $id)
            ->where('deleted', 0)
            ->where('is_custom_field', 1) // 🚫 Only custom fields
            ->where('organization_id', $organizationId)
            ->first();
        
        if (!$field) {
            DB::rollBack();
            return $this->error('Custom field not found or cannot be deleted');
        }

        /* -----------------------------------------
           1️⃣ Delete profile relationships
        ------------------------------------------ */
        ProfileModuleField::where('field_id', $field->id)
            ->where('organization_id', $organizationId)
            ->delete();

        /* -----------------------------------------
           2️⃣ Disable picklist values (safe)
        ------------------------------------------ */
        if (in_array($field->fieldtype, ['picklist', 'multiselect'], true)) {
            DB::table('picklist_values')
                ->where('field_id', $field->id)
                ->update([
                    'status'     => 0,
                    'updated_at' => now(),
                ]);
        }

        /* -----------------------------------------
           3️⃣ Remove stored custom field values
        ------------------------------------------ */
        if (
            $field->tablename &&
            Schema::hasTable($field->tablename)
        ) {
            DB::table($field->tablename)
                ->where('field_id', $field->id)
                ->where('organization_id', $organizationId)
                ->delete();
        }

        /* -----------------------------------------
           4️⃣ Soft delete the field itself
        ------------------------------------------ */
        $field->update([
            'deleted'    => 1,
            'updated_at'=> now(),
        ]);

        DB::commit();

        return $this->success([
            'message'  => 'Custom field deleted successfully',
            'field_id' => $field->id,
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('CUSTOM_FIELD_DELETE_ERROR', [
            'error' => $e->getMessage(),
            'field_id' => $id,
        ]);

        return $this->error('Failed to delete custom field');
    }
}
}