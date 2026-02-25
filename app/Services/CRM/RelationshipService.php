<?php

namespace App\Services\CRM;

use App\Models\AtomModel;
use App\Modules\Api\V1\RelatedRecords\Models\ModuleRelationField;
use App\Models\CrmField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Relationship Service
 * 
 * Handles all relationship operations by reading from module_relation_fields table.
 * This service automatically processes belongs_to, has_many, and many_to_many relationships.
 */
class RelationshipService
{
    /**
     * Process all relationships for a model
     */
    public static function processRelationships(
        AtomModel $model,
        array $relatedRecords = []
    ): void {
        $module = $model->getModuleName();
        $isNew = !$model->exists;

        // Validate belongs_to relationships
        self::validateBelongsToRelationships($model, $module, $isNew);

        // Process has_many relationships (child records)
        self::processHasManyRelationships($model, $module, $relatedRecords, $isNew);

        // Process many_to_many relationships (pivot tables)
        self::processManyToManyRelationships($model, $module, $relatedRecords, $isNew);
    }

    /**
     * Validate belongs_to relationships
     */
    protected static function validateBelongsToRelationships(
        AtomModel $model,
        string $module,
        bool $isNew
    ): void {
        // Get all belongs_to relationships for this module
        $relations = ModuleRelationField::where('modulename', $module)
            ->where('deleted', 0)
            ->get();

        foreach ($relations as $relation) {
            // Get the field name from crm_fields
            $crmField = CrmField::where('id', $relation->field_id)
                ->where('deleted', 0)
                ->first();

            if (!$crmField) {
                continue;
            }

            $fieldName = $crmField->fieldname;
            $relatedModule = $relation->related_module;
            $foreignKeyValue = $model->getAttribute($fieldName);

            // Skip if field is empty (unless required)
            if (empty($foreignKeyValue)) {
                continue;
            }

            // Validate the related record exists and belongs to same organization
            self::validateRelatedRecord($relatedModule, $foreignKeyValue, $fieldName);
        }
    }

    /**
     * Validate that a related record exists and belongs to the same organization
     */
    protected static function validateRelatedRecord(
        string $relatedModule,
        string $relatedId,
        string $fieldName
    ): void {
        $relatedClass = self::getModelClass($relatedModule);

        if (!class_exists($relatedClass)) {
            return; // Module doesn't exist, skip validation
        }

        // Check if record exists in current organization scope
        if (!$relatedClass::where('id', $relatedId)->exists()) {
            // Check if it exists in another organization
            if ($relatedClass::withoutGlobalScopes()->where('id', $relatedId)->exists()) {
                throw new \Exception(
                    "The selected " . str_replace('_', ' ', $fieldName) . " belongs to another organization."
                );
            }

            throw new \Exception(
                "The selected " . str_replace('_', ' ', $fieldName) . " does not exist."
            );
        }
    }

    /**
     * Process has_many relationships (child records like invoice_items)
     */
    protected static function processHasManyRelationships(
        AtomModel $model,
        string $module,
        array $relatedRecords,
        bool $isNew
    ): void {
        // Find all modules that have a belongs_to relationship pointing to this module
        $childRelations = ModuleRelationField::where('related_module', $module)
            ->where('deleted', 0)
            ->get()
            ->groupBy('modulename');

        foreach ($childRelations as $childModule => $relations) {
            // Get the relation name (e.g., 'invoice_items' from 'InvoiceItem')
            $relationName = self::getRelationName($childModule);

            // Skip if no records provided for this relationship
            if (empty($relatedRecords[$relationName])) {
                continue;
            }

            // Get the foreign key field
            $relation = $relations->first();
            $crmField = CrmField::where('id', $relation->field_id)
                ->where('deleted', 0)
                ->first();

            if (!$crmField) {
                continue;
            }

            $foreignKey = $crmField->fieldname;
            $apiFieldName = $crmField->apifieldname ?? lcfirst(str_replace('_', '', ucwords($foreignKey, '_')));
            $childClass = self::getModelClass($childModule);

            if (!class_exists($childClass)) {
                continue;
            }

            // Get existing child IDs (for updates)
            $existingIds = collect();
            if (!$isNew) {
                $existingIds = $childClass::where($foreignKey, $model->id)
                    ->pluck('id');
            }

            $submittedIds = collect();

            // Process each related record
            foreach ($relatedRecords[$relationName] as $record) {
                // Set the foreign key (both DB name and API name for fill/validation)
                $record[$foreignKey] = $model->id;
                $record[$apiFieldName] = $model->id;

                // Normalize field names (camelCase to snake_case)
                $record = self::normalizeFieldNames($record, $foreignKey);

                $itemId = $record['id'] ?? null;

                // For new parent records, always create new children
                if ($isNew) {
                    $itemId = null;
                }

                // Generate UUID if new
                if (!$itemId) {
                    $record['id'] = (string) Str::uuid();
                } else {
                    $submittedIds->push($itemId);
                }

                // Create or update the related model
                $relatedModel = RecordObject::make(
                    $childModule,
                    $itemId,
                    $record,
                    'EditView'
                );

                $relatedModel->save();
            }

            // Delete orphaned records (existing but not in submitted list)
            if (!$isNew) {
                $idsToDelete = $existingIds->diff($submittedIds);
                if ($idsToDelete->isNotEmpty()) {
                    $childClass::whereIn('id', $idsToDelete->all())->delete();
                }
            }
        }
    }

    /**
     * Process many_to_many relationships (pivot tables like comment_rel)
     */
    protected static function processManyToManyRelationships(
        AtomModel $model,
        string $module,
        array $relatedRecords,
        bool $isNew
    ): void {
        // Handle known pivot tables
        $pivotTables = [
            'comment_rel' => [
                'parent_key' => 'comment_id',
                'related_key' => 'parent_id',
                'parent_module_column' => 'parent_module',
                'polymorphic' => true,
            ],
            'activity_relations' => [
                'parent_key' => 'activity_id',
                'related_key' => 'entity_id',
                'parent_module_column' => 'entity_type',
                'polymorphic' => true,
            ],
        ];

        foreach ($pivotTables as $pivotTable => $config) {
            $relationName = str_replace('_', '', $pivotTable); // comment_rel -> commentrel
            $records = $relatedRecords[$relationName] ?? $relatedRecords[$pivotTable] ?? [];

            // Special handling for Activity: if relatedRecords is a direct array and module is Activity
            if ($module === 'Activity' && empty($records) && !empty($relatedRecords) && is_array($relatedRecords) && isset($relatedRecords[0])) {
                // Check if it's the Activity format (has entityType, entityId, relationType)
                if (isset($relatedRecords[0]['entityType']) || isset($relatedRecords[0]['entityId'])) {
                    $records = $relatedRecords;
                }
            }

            if (empty($records)) {
                continue;
            }

            foreach ($records as $relation) {
                $rawRelatedId = $relation[$config['related_key']]
                    ?? $relation[str_replace('_', '', $config['related_key'])] // camelCase variant (entity_id -> entityId)
                    ?? $relation['entityId'] // Direct entityId
                    ?? null;

                $relatedModule = $config['polymorphic']
                    ? ($relation[$config['parent_module_column']]
                        ?? $relation[lcfirst(str_replace('_', '', ucwords($config['parent_module_column'], '_')))] // camelCase (entity_type -> entityType)
                        ?? $relation['entityType'] // Direct entityType
                        ?? $module) // fallback to current module
                    : null;

                if (!$rawRelatedId || !$relatedModule) {
                    continue;
                }

                if ($pivotTable === 'activity_relations') {
                    $relatedModule = self::normalizeEntityType($relatedModule);
                }

                // Normalize related IDs:
                // - Some clients send `entityId` as an array (multi-select). Insert one pivot row per ID.
                // - Avoid "Array to string conversion" by never inserting arrays into scalar DB columns.
                $relatedIds = is_array($rawRelatedId) ? array_values($rawRelatedId) : [$rawRelatedId];
                $relatedIds = array_values(array_filter($relatedIds, function ($id) {
                    return is_string($id) || is_int($id);
                }));

                // For polymorphic, determine the parent key value
                // For Activity, use model->id as activity_id
                $parentKeyValue = null;
                if ($config['parent_key'] === 'comment_id') {
                    $parentKeyValue = $relation['comment_id'] ?? $relation['commentId'] ?? null;
                } elseif ($config['parent_key'] === 'activity_id') {
                    // For Activity, always use the model's ID
                    $parentKeyValue = $model->id;
                }

                if ($config['polymorphic'] && $parentKeyValue) {
                    // `activity_relations.relation_type` is NOT NULL. Skip inserts that don't include it.
                    if ($pivotTable === 'activity_relations' && !isset($relation['relationType']) && !isset($relation['relation_type'])) {
                        continue;
                    }

                    foreach ($relatedIds as $relatedId) {
                        $relatedId = (string) $relatedId;

                        // Check if record already exists
                        $exists = DB::table($pivotTable)
                            ->where($config['parent_key'], $parentKeyValue)
                            ->where($config['related_key'], $relatedId)
                            ->where($config['parent_module_column'], $relatedModule)
                            ->exists();

                        // Check if table has organization_id column
                        $hasOrgId = DB::getSchemaBuilder()->hasColumn($pivotTable, 'organization_id');

                        // Prepare insert/update data
                        $insertData = [
                            $config['parent_key'] => $parentKeyValue,
                            $config['related_key'] => $relatedId,
                            $config['parent_module_column'] => $relatedModule,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        // Add organization_id only if column exists
                        if ($hasOrgId) {
                            $insertData['organization_id'] = auth()->user()->organization_id ?? ($model->organization_id ?? null);
                        }

                        // Add relation_type if provided (for activity_relations)
                        if ($pivotTable === 'activity_relations' && isset($relation['relationType'])) {
                            $insertData['relation_type'] = $relation['relationType'];
                        } elseif ($pivotTable === 'activity_relations' && isset($relation['relation_type'])) {
                            $insertData['relation_type'] = $relation['relation_type'];
                        }

                        // Add ID only for new records
                        if (!$exists) {
                            $insertData['id'] = (string) Str::uuid();
                        }

                        // Insert/update pivot record
                        DB::table($pivotTable)->updateOrInsert(
                            [
                                $config['parent_key'] => $parentKeyValue,
                                $config['related_key'] => $relatedId,
                                $config['parent_module_column'] => $relatedModule,
                            ],
                            $insertData
                        );
                    }
                } elseif (!$config['polymorphic']) {
                    // Non-polymorphic pivot
                    foreach ($relatedIds as $relatedId) {
                        $relatedId = (string) $relatedId;
                        DB::table($pivotTable)->updateOrInsert(
                            [
                                $config['parent_key'] => $model->id,
                                $config['related_key'] => $relatedId,
                            ],
                            [
                                'id' => (string) Str::uuid(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }
            }
        }
    }

    /**
     * Load related records for a model
     */
    public static function loadRelatedRecords(AtomModel $model): array
    {
        $module = $model->getModuleName();
        $relatedRecords = [];

        // Load has_many relationships
        $relatedRecords = array_merge(
            $relatedRecords,
            self::loadHasManyRelationships($model, $module)
        );

        // Load many_to_many relationships
        $relatedRecords = array_merge(
            $relatedRecords,
            self::loadManyToManyRelationships($model, $module)
        );

        return $relatedRecords;
    }

    /**
     * Load has_many relationships
     */
    protected static function loadHasManyRelationships(AtomModel $model, string $module): array
    {
        $relatedRecords = [];

        // Find all modules that have a belongs_to relationship pointing to this module
        $childRelations = ModuleRelationField::where('related_module', $module)
            ->where('deleted', 0)
            ->get()
            ->groupBy('modulename');

        foreach ($childRelations as $childModule => $relations) {
            $relation = $relations->first();
            $crmField = CrmField::where('id', $relation->field_id)
                ->where('deleted', 0)
                ->first();

            if (!$crmField) {
                continue;
            }

            $foreignKey = $crmField->fieldname;
            $childClass = self::getModelClass($childModule);

            if (!class_exists($childClass)) {
                continue;
            }

            // Get child record IDs with organization filtering
            $orgId = auth()->user()->organization_id ?? null;
            $childIds = $childClass::where($foreignKey, $model->id)
                ->when($orgId, function ($q) use ($childClass, $orgId) {
                    $table = (new $childClass)->getTable();
                    if (DB::getSchemaBuilder()->hasColumn($table, 'organization_id')) {
                        $q->where('organization_id', $orgId);
                    }
                })
                ->pluck('id');

            $relationName = self::getRelationName($childModule);
            $relatedItems = [];

            foreach ($childIds as $childId) {
                try {
                    $childRecord = RecordObject::make($childModule, $childId)
                        ->transformToApiFormat();
                    $relatedItems[] = $childRecord;
                } catch (\Exception $e) {
                    // Skip records that can't be loaded
                    continue;
                }
            }

            $relatedRecords[$relationName] = $relatedItems;
        }

        return $relatedRecords;
    }

    /**
     * Load many_to_many relationships
     */
    protected static function loadManyToManyRelationships(AtomModel $model, string $module): array
    {
        $relatedRecords = [];

        // Handle comment_rel (polymorphic)
        $commentRels = DB::table('comment_rel')
            ->where('parent_id', $model->id)
            ->where('parent_module', $module)
            ->get();

        if ($commentRels->isNotEmpty()) {
            $relatedRecords['comment_rel'] = [];
            foreach ($commentRels as $rel) {
                try {
                    $comment = RecordObject::make('Comment', $rel->comment_id)
                        ->transformToApiFormat();
                    $relatedRecords['comment_rel'][] = $comment;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        // Handle activity_relations (polymorphic)
        $activityRels = DB::table('activity_relations')
            ->where('entity_id', $model->id)
            ->where('entity_type', $module)
            ->get();

        if ($activityRels->isNotEmpty()) {
            $relatedRecords['activity_relations'] = [];
            foreach ($activityRels as $rel) {
                try {
                    $activity = RecordObject::make('Activity', $rel->activity_id)
                        ->transformToApiFormat();
                    $relatedRecords['activity_relations'][] = $activity;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $relatedRecords;
    }

    /**
     * Get model class name for a module
     */
    protected static function getModelClass(string $module): string
    {
        return "\\App\\Modules\\Api\\V1\\{$module}\\Models\\{$module}";
    }

    /**
     * Convert module name to relation name (e.g., 'InvoiceItem' -> 'invoice_items')
     */
    protected static function getRelationName(string $module): string
    {
        // Convert PascalCase to snake_case and pluralize
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $module));
        return Str::plural($snakeCase);
    }

    /**
     * Normalize field names (e.g., invoiceId -> invoice_id)
     */
    protected static function normalizeFieldNames(array $record, string $foreignKey): array
    {
        // Convert camelCase to snake_case for foreign keys
        $camelCaseKey = lcfirst(str_replace('_', '', ucwords($foreignKey, '_')));

        if (isset($record[$camelCaseKey]) && !isset($record[$foreignKey])) {
            $record[$foreignKey] = $record[$camelCaseKey];
            unset($record[$camelCaseKey]);
        }

        return $record;
    }

    /**
     * Normalize common entity_type typos for activity relations
     */
    protected static function normalizeEntityType(string $entityType): string
    {
        $normalized = trim($entityType);
        $map = [
            'Quotaion' => 'Quotation',
            'quotaion' => 'Quotation',
        ];

        return $map[$normalized] ?? $normalized;
    }

    /**
     * Get all belongs_to relationships for a module
     */
    public static function getBelongsToRelationships(string $module): array
    {
        $relations = ModuleRelationField::where('modulename', $module)
            ->where('deleted', 0)
            ->get();

        return $relations->map(function ($relation) {
            $crmField = CrmField::where('id', $relation->field_id)
                ->where('deleted', 0)
                ->first();

            return [
                'field_id' => $relation->field_id,
                'field_name' => $crmField->fieldname ?? null,
                'related_module' => $relation->related_module,
            ];
        })->toArray();
    }

    /**
     * Get all has_many relationships for a module (modules that reference this module)
     */
    public static function getHasManyRelationships(string $module): array
    {
        $relations = ModuleRelationField::where('related_module', $module)
            ->where('deleted', 0)
            ->get()
            ->groupBy('modulename');

        return $relations->map(function ($relations) {
            $relation = $relations->first();
            $crmField = CrmField::where('id', $relation->field_id)
                ->where('deleted', 0)
                ->first();

            return [
                'module' => $relation->modulename,
                'foreign_key' => $crmField->fieldname ?? null,
                'field_id' => $relation->field_id,
            ];
        })->values()->toArray();
    }
}
