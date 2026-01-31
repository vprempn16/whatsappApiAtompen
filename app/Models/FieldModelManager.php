<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Modules\Api\V1\ModuleRelationFields\Models\ModuleRelationFields;
use App\Services\PermissionService;

class FieldModelManager
{
    protected string $module;
    protected array $fieldModels = [];
    protected array $apiFormFields = [];
    protected string $viewType = 'DetailView';
    protected bool $profileValidation = false;

    protected $user;
    protected ?PermissionService $permissionService = null;

    /**
     * ------------------------------------------------------------
     * Constructor (NO AUTO LOAD)
     * ------------------------------------------------------------
     */
    public function __construct(
        string $module,
        string $viewType = 'DetailView',
        bool $profileValidation = false
    ) {
        $this->module            = \App\Services\ModuleService::resolveName($module);
        $this->viewType          = $viewType;
        $this->profileValidation = $profileValidation;

        $this->user = Auth::user();

        if ($this->user && $this->profileValidation && $this->user->is_admin !== 1) {
            $this->permissionService = new PermissionService($this->user);
        }
    }

    /**
     * ------------------------------------------------------------
     * Factory
     * ------------------------------------------------------------
     */
    public static function make(
        string $module,
        string $viewType = 'DetailView',
        bool $profileValidation = false
    ): static {
        return (new static($module, $viewType, $profileValidation))->load();
    }

    /**
     * ------------------------------------------------------------
     * Explicit Field Loader (REPLACEMENT FOR loadFields)
     * ------------------------------------------------------------
     */
    public function load(): self
    {
        $organizationId = auth()->user()?->organization_id ?? null;

        // Displaytype rules per view:
        // CreateView → [1], EditView → [1], DetailView → [1, 3], ListView → [1, 3]
        if ($this->viewType == 'EditView' || $this->viewType == 'CreateView') {
            $displayTypes = [1];
        } elseif ($this->viewType == 'DetailView' || $this->viewType == 'ListView') {
            $displayTypes = [1, 3];
        } else {
            // Fallback: default to [1, 3] for any other view types
            $displayTypes = [1, 3];
        }
        $fields = CrmField::query()
            ->where('modulename', $this->module)
            ->whereIn('displaytype', $displayTypes)
            ->where('deleted', 0)
            ->where(function ($q) use ($organizationId) {
                $q->where('is_custom_field', 0)
                  ->orWhere(function ($q2) use ($organizationId) {
                      $q2->where('is_custom_field', 1)
                         ->where('organization_id', $organizationId);
                  });
            })
            ->orderBy('seq', 'asc')
            ->get();

        foreach ($fields as $field) {
            $model   = new FieldModel($field);
            $apiName = $model->getAPIName();

            $this->fieldModels[$apiName] = $model;
        }

        return $this;
    }

    /**
     * ------------------------------------------------------------
     * Permission Action Resolver
     * ------------------------------------------------------------
     */
    protected function resolveAction(): string
    {
        return match ($this->viewType) {
            'CreateView' => 'create',
            'EditView'   => 'edit',
            default      => 'view',
        };
    }

    /**
     * ------------------------------------------------------------
     * PUBLIC METHODS
     * ------------------------------------------------------------
     */
    public function getFields(): array
    {
        return $this->fieldModels;
    }

    public function getApiFormFields(): array
    {
        $fields = [];
        $action = $this->resolveAction();

        // Admin bypass: no permission filtering
        if ($this->user && $this->user->is_admin === 1) {
            foreach ($this->fieldModels as $model) {
                $fieldArray = [
                    'id'              => $model->getId(),
                    'fieldname'       => $model->getAPIName(),
                    'fieldlabel'      => $model->getLabel(),
                    'mandatory'       => $model->isMandatory(),
                    'fieldtype'       => $model->getFieldType(),
                    'displaytype'     => $model->getDisplaytype(),
                    'is_custom_field' => $model->isCustomField(),
                ];

                if (in_array(strtolower($fieldArray['fieldtype']), ['picklist', 'multiselect'])) {
                    $fieldArray['options'] = $this->getPicklistOptions($model->getId());
                }

                if ($fieldArray['fieldtype'] === 'relationPickList') {
                    $relation = ModuleRelationFields::where(
    'field_id',
    $model->getId()
)->first();


                    $fieldArray['module'] = $relation->related_module ?? null;
                }
                
                $fields[] = $fieldArray;
            }
            return $fields;
        }

        // Non-admin users: permission filtering via PermissionService
        if ($this->permissionService && $this->profileValidation && $this->module !== 'User') {
            if (!$this->permissionService->hasPermission($this->module, $action)) {
                throw new \Exception(
                    "Unauthorized: No {$action} permission for module {$this->module}"
                );
            }
        }

        foreach ($this->fieldModels as $model) {
            $fieldId   = $model->getId();
            $apiName   = $model->getAPIName();
            $type      = $model->getFieldType();
            $typeLower = strtolower($type);
            $display   = $model->getDisplaytype();

            if ($this->permissionService && $this->profileValidation && $apiName !== 'id' && $this->module !== 'User') {

                if (
                    in_array($this->viewType, ['ListView', 'DetailView'], true) &&
                    !$this->permissionService->canViewField($this->module, $fieldId)
                ) {
                    continue;
                }

                if (
                    in_array($this->viewType, ['CreateView', 'EditView'], true) &&
                    !$this->permissionService->canWriteField($this->module, $fieldId)
                ) {
                    continue;
                }
            }

            $fieldArray = [
                'id'              => $fieldId,
                'fieldname'       => $apiName,
                'fieldlabel'      => $model->getLabel(),
                'mandatory'       => $model->isMandatory(),
                'fieldtype'       => $type,
                'displaytype'     => $display,
                'is_custom_field' => $model->isCustomField(),
            ];

            if (in_array($typeLower, ['picklist', 'multiselect'])) {
                $fieldArray['options'] = $this->getPicklistOptions($fieldId);
            }

            if ($typeLower === 'relationpicklist') {
                $relation = ModuleRelationFields::where(
    'field_id',
    $model->getId()
)->first();


                $fieldArray['module'] = $relation->related_module ?? null;
            }
            $fields[] = $fieldArray;
        }

        return $fields;
    }

    public function getCustomFields(): array
    {
        return array_filter(
            $this->fieldModels,
            fn ($field) => $field->isCustomField()
        );
    }

    public function getFieldModel(string $apiName): ?FieldModel
    {
        return $this->fieldModels[$apiName] ?? null;
    }

    public function getFieldToApiMap(): array
    {
        return collect($this->fieldModels)
            ->mapWithKeys(fn ($f) => [$f->getFieldName() => $f->getAPIName()])
            ->toArray();
    }

    /**
     * ------------------------------------------------------------
     * Validation
     * ------------------------------------------------------------
     */
    public function validate(array &$input): void
{
    $errors = [];

    foreach ($this->getFields() as $fieldModel) {
        $apiField = $fieldModel->getAPIName();
        $value    = $input[$apiField] ?? null;

        try {
            $input[$apiField] = $fieldModel->validate($value); // ✅ WRITE BACK
        } catch (ValidationException $e) {
            $errors[$apiField] = $e->errors()[$apiField]
                ?? [$e->getMessage()];
        }
    }

    if (!empty($errors)) {
        throw ValidationException::withMessages($errors);
    }
}


    public function validatePartial(array &$input, array $onlyFields): void
{
    $errors = [];
    $onlyLookup = array_flip($onlyFields);

    foreach ($this->getFields() as $fieldModel) {
        $apiField = $fieldModel->getAPIName();

        if (!isset($onlyLookup[$apiField])) {
            continue;
        }

        $value = $input[$apiField] ?? null;

        try {
            // ✅ sanitize + write back
            $input[$apiField] = $fieldModel->validate($value);
        } catch (ValidationException $e) {
            $errors[$apiField] = $e->errors()[$apiField]
                ?? [$e->getMessage()];
        }
    }

    if (!empty($errors)) {
        throw ValidationException::withMessages($errors);
    }
}


    /**
     * ------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------
     */
    private function getPicklistOptions(string $fid): array
    {
        if (!CrmField::where('id', $fid)->exists()) {
            return [];
        }

        return DB::table('picklist_values')
            ->where('field_id', $fid)
            ->where('status', 1)
            ->orderBy('sort_order')
            ->get(['value', 'label'])
            ->toArray();
    }

    /**
     * Resolve module + apiField to crm_fields.id, optionally scoped by organization.
     * When $organizationId is provided, returns only fields for that org or system (is_custom_field=0) fields.
     */
    public static function getFieldId(string $module, string $apiField, ?string $organizationId = null): ?string
    {
        $query = DB::table('crm_fields')
            ->where('modulename', $module)
            ->where('apifieldname', $apiField)
            ->where('deleted', 0);

        if ($organizationId !== null) {
            $query->where(function ($q) use ($organizationId) {
                $q->where('is_custom_field', 0)
                    ->orWhere('organization_id', $organizationId);
            });
        }

        return $query->value('id');
    }

    public static function getApiFieldName(string $fieldId): ?string
    {
        return DB::table('crm_fields')
            ->where('id', $fieldId)
            ->value('apifieldname');
    }

    /**
     * Resolve multiple field IDs to api names in one query (avoids N+1).
     * Returns map: field_id => apifieldname
     */
    public static function getApiFieldNames(array $fieldIds): array
    {
        if (empty($fieldIds)) {
            return [];
        }
        $fieldIds = array_unique($fieldIds);
        $rows = DB::table('crm_fields')
            ->whereIn('id', $fieldIds)
            ->get(['id', 'apifieldname']);
        $map = [];
        foreach ($rows as $row) {
            $map[$row->id] = $row->apifieldname;
        }
        return $map;
    }
}