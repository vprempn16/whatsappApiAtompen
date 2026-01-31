<?php

namespace App\Services;

use App\Models\Filter;
use App\Models\FilterCondition;
use App\Models\FieldModelManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class FilterService
{
    /**
    
     * 
     * @param Builder $query - Eloquent query builder
     * @param string $filterId - Filter UUID
     * @param string|null $moduleName - Module name for validation
     * @return Builder
     */
    public static function applyFilter(Builder $query, string $filterId, string $moduleName = null): Builder
    {
        $filter = Filter::with('conditions')->find($filterId);

        if (!$filter) {
            Log::warning("Filter not found: {$filterId}");
            return $query;
        }

        if ($moduleName && $filter->module_name !== $moduleName) {
            Log::warning("Filter module mismatch. Expected: {$moduleName}, Got: {$filter->module_name}");
            return $query;
        }

        return static::applyConditions($query, $filter->conditions, $filter->module_name);
    }

    /**
     * Apply conditions collection to query
     
     */
    public static function applyConditions(Builder $query, $conditions, string $moduleName): Builder
    {
        if ($conditions->isEmpty()) {
            return $query;
        }

        $fieldManager = FieldModelManager::make($moduleName);
        $fields = $fieldManager->getFields();
        $tableName = $query->getModel()->getTable();

        // Group conditions by type (AND/OR)
        $andConditions = $conditions->where('condition_type', 'AND');
        $orConditions = $conditions->where('condition_type', 'OR');

        // Apply AND conditions directly
        foreach ($andConditions as $condition) {
            static::applyCondition($query, $condition, $fields, $tableName, false);
        }

        // Apply OR conditions in a grouped where clause
        if ($orConditions->isNotEmpty()) {
            $query->where(function ($q) use ($orConditions, $fields, $tableName) {
                foreach ($orConditions as $condition) {
                    static::applyCondition($q, $condition, $fields, $tableName, true);
                }
            });
        }
        return $query;
    }

    /**
     * Apply a single condition to query
     */
    protected static function applyCondition(
    Builder $query, 
    FilterCondition $condition, 
    array $fields, 
    string $tableName, 
    bool $isOr = false
) {
    $fieldName = $condition->field_name;
    $field = $fields[$fieldName] ?? null;

    if (!$field) {
        Log::warning("Field not found in module fields array: {$fieldName}");
        return;
    }

    // Try to get DB field from crm_fields mapping
    $dbFieldName = DB::table('crm_fields')
        ->where('apifieldname', $fieldName)
        ->value('fieldname');

    // If not found, fallback to snake_case version
    if (!$dbFieldName) {
        $dbFieldName = Str::snake($fieldName);
    }

    // Validate column exists
    if (!Schema::hasColumn($tableName, $dbFieldName)) {
        Log::warning("Column {$dbFieldName} does not exist in table {$tableName}");
        return;
    }

    // Apply operator
    $operator = $condition->operator_key;
    $value = $condition->value;
    $queryMethod = $isOr ? 'orWhere' : 'where';
    
    // SECURITY: Escape special LIKE characters to prevent injection
    $escapedValue = addcslashes($value ?? '', '%_\\');

    switch ($operator) {
        case 'contains':
            $query->$queryMethod("{$tableName}.{$dbFieldName}", 'LIKE', "%{$escapedValue}%");
            break;

        case 'starts_with':
            $query->$queryMethod("{$tableName}.{$dbFieldName}", 'LIKE', "{$escapedValue}%");
            break;

        case 'ends_with':
            $query->$queryMethod("{$tableName}.{$dbFieldName}", 'LIKE', "%{$escapedValue}");
            break;

        case 'eq':
            $query->$queryMethod("{$tableName}.{$dbFieldName}", '=', $value);
            break;

        case 'neq':
            $query->$queryMethod("{$tableName}.{$dbFieldName}", '!=', $value);
            break;

        case 'is_null':
            if ($isOr) {
                $query->orWhereNull("{$tableName}.{$dbFieldName}");
            } else {
                $query->whereNull("{$tableName}.{$dbFieldName}");
            }
            break;

        case 'is_not_null':
            if ($isOr) {
                $query->orWhereNotNull("{$tableName}.{$dbFieldName}");
            } else {
                $query->whereNotNull("{$tableName}.{$dbFieldName}");
            }
            break;

        default:
            $query->$queryMethod("{$tableName}.{$dbFieldName}", '=', $value);
            break;
    }
}

    /**
     * Apply condition for custom fields
     */
    protected static function applyCustomFieldCondition(
        Builder $query, 
        FilterCondition $condition, 
        string $fieldName, 
        string $tableName, 
        bool $isOr = false
    ) {
        $moduleName = class_basename($query->getModel());
        $customTable = 'l' . strtolower($moduleName) . '_custom_values';

        if (!Schema::hasTable($customTable)) {
            Log::warning("Custom table not found: {$customTable}");
            return;
        }

        $joinAlias = $customTable . '_' . $fieldName;
        
        // Check if join already exists
        $joins = $query->getQuery()->joins ?? [];
        $joinExists = collect($joins)->contains(function ($join) use ($joinAlias) {
            return str_contains($join->table, $joinAlias);
        });

        // Add join if not exists
        if (!$joinExists) {
            $query->leftJoin("{$customTable} as {$joinAlias}", function ($join) use ($tableName, $joinAlias, $fieldName) {
                $join->on("{$tableName}.id", '=', "{$joinAlias}.record_id")
                     ->where("{$joinAlias}.field_name", '=', $fieldName);
            });
        }

        // Apply the condition on the custom field value
        $operator = $condition->operator_key;
        $value = $condition->value;
        $queryMethod = $isOr ? 'orWhere' : 'where';

        switch ($operator) {
            case 'eq':
                $query->$queryMethod("{$joinAlias}.field_value", '=', $value);
                break;
            case 'neq':
                $query->$queryMethod("{$joinAlias}.field_value", '!=', $value);
                break;
            case 'like':
            case 'contains':
                $query->$queryMethod("{$joinAlias}.field_value", 'LIKE', "%{$value}%");
                break;
            case 'starts_with':
                $query->$queryMethod("{$joinAlias}.field_value", 'LIKE', "{$value}%");
                break;
            case 'ends_with':
                $query->$queryMethod("{$joinAlias}.field_value", 'LIKE', "%{$value}");
                break;
            case 'is_null':
                $query->$queryMethod("{$joinAlias}.field_value", 'IS NULL');
                break;
            case 'is_not_null':
                $query->$queryMethod("{$joinAlias}.field_value", 'IS NOT NULL');
                break;
            default:
                $query->$queryMethod("{$joinAlias}.field_value", '=', $value);
                break;
        }
    }

    /**
     * Get filtered list for a module
     * 
     * @param string $moduleName - Module name (Contact, Lead, etc.)
     * @param string|null $filterId - Filter UUID
     * @param int $perPage - Records per page
     * @param int $page - Current page
     * @param array $additionalFilters - Additional filters to apply
     * @return array
     */
    public static function getFilteredList(
        string $moduleName,
        string $filterId = null,
        int $perPage = 20,
        int $page = 1,
        array $additionalFilters = []
    ): array {
        // Resolve the module name (handle plural to singular)
        $resolvedModuleName = \App\Services\ModuleService::resolveName($moduleName);
        $modelClass = "App\\Modules\\Api\\V1\\{$resolvedModuleName}\\Models\\{$resolvedModuleName}";
        
        if (!class_exists($modelClass)) {
            throw new \Exception("Model not found: {$modelClass}");
        }

        $model = new $modelClass;
        $query = $model->newQuery();
        $tableName = $model->getTable();
        // Apply soft delete filter
        if (Schema::hasColumn($tableName, 'deleted')) {
            $query->where('deleted', 0);
        }

        // Apply organization scope
        if (Schema::hasColumn($tableName, 'organization_id')) {
            $query->where('organization_id', auth()->user()->organization_id);
        }

        // Apply saved filter if provided
        if ($filterId) {
            static::applyFilter($query, $filterId, $moduleName);
        }
$filter = Filter::where('id', $filterId)->first();

$visibleColumns = $filter?->header_details['columns'] ?? [];
if (empty($visibleColumns)) {
    $fieldManager = FieldModelManager::make($moduleName, 'ListView', true);
    $visibleColumns = collect($fieldManager->getApiFormFields())
        ->pluck('fieldname')
        ->filter()
        ->values()
        ->toArray();
}
 
        // Apply additional filters (from query params)
        foreach ($additionalFilters as $field => $value) {
            if (!is_null($value) && $value !== '' && Schema::hasColumn($tableName, $field)) {
                if (in_array($field, ['name', 'title', 'first_name', 'last_name', 'email'])) {
                    $query->where($field, 'LIKE', '%' . $value . '%');
                } else {
                    $query->where($field, $value);
                }
            }
        }

        $query->orderBy('created_at', 'desc');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

       $list = $paginator->getCollection()->map(function ($record) use ($visibleColumns) {

    $data = $record->transformToApiFormat();

    // Always include ID
    $allowedKeys = array_merge($visibleColumns, ['id']);

    // Only include _label fields that actually exist in the data
    // (transformToApiFormat only creates _label for reference fields)
    $allowedKeysWithLabels = $allowedKeys;
    
    foreach ($visibleColumns as $col) {
        $labelKey = $col . '_label';
        if (isset($data[$labelKey])) {
            $allowedKeysWithLabels[] = $labelKey;
        }
    }
    
    return array_intersect_key(
        $data,
        array_flip($allowedKeysWithLabels)
    );
});


        return [
            'details' => $list,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filter_id' => $filterId,
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ]
        ];
    }

    /**
     * Get available operators for a field type
     */
    public static function getOperatorsForFieldType(string $fieldType): array
    {
        $fieldType = strtolower($fieldType);
        $operatorSets = [
            'text' => ['eq', 'neq', 'like', 'not_like', 'starts_with', 'ends_with', 'contains', 'is_null', 'is_not_null'],
            'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'is_not_null'],
            'date' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'is_not_null'],
            'boolean' => ['eq', 'neq'],
            'picklist' => ['eq', 'neq', 'in', 'not_in', 'is_null', 'is_not_null'],
            'reference' => ['eq', 'neq', 'in', 'not_in', 'is_null', 'is_not_null'],
        ];

        $typeMap = [
            'string' => 'text',
            'text' => 'text',
            'textarea' => 'text',
            'email' => 'text',
            'phone' => 'text',
            'url' => 'text',
            'integer' => 'number',
            'decimal' => 'number',
            'float' => 'number',
            'date' => 'date',
            'datetime' => 'date',
            'timestamp' => 'date',
            'boolean' => 'boolean',
            'picklist' => 'picklist',
            'relationPickList' => 'picklist',
            'multipicklist' => 'picklist',
            'reference' => 'reference',
            'owner' => 'reference',
        ];

        $mappedType = $typeMap[strtolower($fieldType)] ?? 'text';
        return $operatorSets[$mappedType] ?? $operatorSets['text'];
    }

    /**
     * Get filter configuration for a module
     */
    public static function getFilterConfig(string $moduleName): array
    {
        $fieldManager = FieldModelManager::make($moduleName);
        $fields = $fieldManager->getFields();

        $filterableFields = [];
        foreach ($fields as $field) {
            if ($field->getDisplaytype() == 1) { // Only editable fields
                $filterableFields[] = [
                    'field_name' => $field->getFieldName(),
                    'api_name' => $field->getAPIName(),
                    'label' => $field->getLabel(),
                    'type' => $field->getFieldType(),
                    'is_custom' => $field->isCustomField(),
                    'operators' => static::getOperatorsForFieldType($field->getFieldType()),
                ];
            }
        }

        return [
            'module' => $moduleName,
            'fields' => $filterableFields,
            'operators' => FilterCondition::getOperators(),
            'condition_types' => ['AND', 'OR'],
        ];
    }

    /**
     * Get a query builder instance with filter conditions applied.
     *
     * @param Filter $filter
     * @return Builder
     * @throws \Exception
     */
    public function getRecordsQueryBuilder(Filter $filter): Builder
    { 

        $moduleName = ucfirst($filter->module_name);
        // Resolve module name (handle plural to singular)
        $resolvedModuleName = \App\Services\ModuleService::resolveName($moduleName);
        $modelClass = 'App\\Modules\\Api\\V1\\' . $resolvedModuleName . '\\Models\\' . $resolvedModuleName;

        if (!class_exists($modelClass)) {
            throw new \Exception("Model for module {$filter->module_name} not found.");
        }

        $query = $modelClass::query();

        $filter->load('conditions');

        $query->where(function ($q) use ($filter) {
            foreach ($filter->conditions as $condition) {
                $boolean = strtolower($condition->condition_type) === 'or' ? 'or' : 'and';
                
                // Simple operator mapping; expand as needed
                $operatorMap = [
                    'equals' => '=',
                    'not_equal_to' => '!=',
                    'greater_than' => '>',
                    'less_than' => '<',
                    'greater_than_or_equal_to' => '>=',
                    'less_than_or_equal_to' => '<=',
                ];

                switch ($condition->operator_key) {
                    case 'is_empty':
                        $q->whereNull($condition->field_name, $boolean);
                        break;
                    case 'is_not_empty':
                        $q->whereNotNull($condition->field_name, $boolean);
                        break;
                    case 'contains':
                        $q->where($condition->field_name, 'LIKE', '%' . $condition->value . '%', $boolean);
                        break;
                    case 'does_not_contain':
                        $q->where($condition->field_name, 'NOT LIKE', '%' . $condition->value . '%', $boolean);
                        break;
                    default:
                        if (isset($operatorMap[$condition->operator_key])) {
                            $q->where($condition->field_name, $operatorMap[$condition->operator_key], $condition->value, $boolean);
                        }
                        break;
                }
            }
        });

        return $query;
    }
}