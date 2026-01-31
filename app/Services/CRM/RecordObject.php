<?php

namespace App\Services\CRM;

use App\Models\AtomModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\FieldModelManager;
use App\Services\PermissionService;
use App\Services\CRM\RelationshipService;

class RecordObject
{
    public static function make(
        string $module,
        ?string $id = null,
        array $data = [],
        string $viewType = 'DetailView'
    ): AtomModel {
        if ($id === 'new') {
            $id = null;
        }

        // Resolve module name (handle plural to singular and special mappings)
        $resolvedModule = \App\Services\ModuleService::resolveName($module);

        $class = "\\App\\Modules\\Api\\V1\\{$resolvedModule}\\Models\\{$resolvedModule}";
        $modelClass = class_exists($class) ? $class : AtomModel::class;

        $exempt = [
            'Organization',
            'User',
            'GlobalSearchIndex',
            'ModuleNumberingDetail',
            'Asset',
            'AuditLog',
            'ModuleRelationFields',
            'Comment'
        ];

        if ($id) {
            try {
                $model = $modelClass::findOrFail($id);
                if (method_exists($model, 'loadCustomValues')) {
                    $model->loadCustomValues();
                }
            } catch (ModelNotFoundException $e) {
                $other = $modelClass::withoutGlobalScopes()->find($id);
                if (
                    $other &&
                    !in_array($module, $exempt, true) &&
                    isset($other->organization_id) &&
                    auth()->user() &&
                    $other->organization_id !== auth()->user()->organization_id
                ) {
                    throw new \Exception("This is not your organization’s record.");
                }
                throw $e;
            }
        } else {
            $model = new $modelClass();
        }

        // Admin bypass for permission checks
        $user = auth()->user();
        if (!$user) {
            throw new \Exception("Unauthenticated user");
        }
        if ($user->is_admin !== 1) {
            $permissionService = new PermissionService($user);

            // Module-level permission check: derive $action from $viewType
            $action = match ($viewType) {
                'CreateView' => 'create',
                'EditView' => 'edit',
                default => 'view',
            };

            if (!$permissionService->hasPermission($module, $action)) {
                throw new \Exception(
                    "Unauthorized: Module permission denied for {$action} on {$module}"
                );
            }

            if (!empty($data)) {
                if (!method_exists($model, 'fill')) {
                    throw new \RuntimeException(
                        "Model [{$modelClass}] does not support fill()."
                    );
                }

                $clean = [];
                $fieldManager = FieldModelManager::make(
                    $module,
                    $viewType,
                    true
                );

                foreach ($data as $apiField => $value) {
                    $fieldId = $fieldManager->getFieldId($module, $apiField);

                    if (!$fieldId) {
                        // Unknown field → ignore
                        continue;
                    }

                    // Write permission enforced only for non-admins
                    if (!$permissionService->canWriteField($module, $fieldId)) {
                        throw new \Exception(
                            "Unauthorized: Field {$apiField} is readonly"
                        );
                    }

                    $clean[$apiField] = $value;
                }

                $model->fill($clean);
            }
        } else {
            // Admin fills data directly without permission checks
            if (!empty($data) && method_exists($model, 'fill')) {
                $model->fill($data);
            }
        }

        if (method_exists($model, 'setViewType')) {
            $model->setViewType($viewType);
        } else {
            $model->_viewType = $viewType;
        }

        return $model;
    }

    public static function saveWithRelations(
        AtomModel $model,
        array $relatedRecords = []
    ): AtomModel {
        return DB::transaction(function () use ($model, $relatedRecords) {
            // Save the main model first
            $model->save();

            // Process all relationships using the centralized RelationshipService
            RelationshipService::processRelationships($model, $relatedRecords);

            return $model;
        });
    }
}