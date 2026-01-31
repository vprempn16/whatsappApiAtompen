<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\CrmField;

class FilterCondition extends Model
{
    // Add these lines:
    public $incrementing = false;
    protected $keyType = 'string';

    protected static function booted()
    {
        parent::booted();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    protected $guarded = [];
    
    protected $casts = [
        'value' => 'string',
    ];

    // Relationships
    public function filter()
    {
        return $this->belongsTo(Filter::class, 'filter_id');
    }

    // Available operators mapping
    public static function getOperators(): array
    {
        return [
            'eq' => '=',
            'neq' => '!=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'LIKE',
            'not_like' => 'NOT LIKE',
            'in' => 'IN',
            'not_in' => 'NOT IN',
            'is_null' => 'IS NULL',
            'is_not_null' => 'IS NOT NULL',
            'between' => 'BETWEEN',
            'starts_with' => 'STARTS WITH',
            'ends_with' => 'ENDS WITH',
            'contains' => 'CONTAINS',
        ];
    }

    // Get SQL operator for this condition
    public function getOperator(): string
    {
        $operators = static::getOperators();
        return $operators[$this->operator_key] ?? '=';
    }

    /**
     * Apply this condition to a query builder
     */
    public function applyToQuery($query, string $tableName = null)
    {
        $field = $this->field_name;
        $operator = $this->operator_key;
        $value = $this->value;
        $conditionType = strtolower($this->condition_type);

        // Add table name prefix if provided
        if ($tableName) {
            $field = $tableName . '.' . $field;
        }
        // Use 'where' for AND, 'orWhere' for OR
        $queryMethod = $conditionType === 'or' ? 'orWhere' : 'where';
        switch ($operator) {
            case 'eq':
                $query->$queryMethod($field, '=', $value);
                break;

            case 'neq':
                $query->$queryMethod($field, '!=', $value);
                break;

            case 'gt':
                $query->$queryMethod($field, '>', $value);
                break;

            case 'gte':
                $query->$queryMethod($field, '>=', $value);
                break;

            case 'lt':
                $query->$queryMethod($field, '<', $value);
                break;

            case 'lte':
                $query->$queryMethod($field, '<=', $value);
                break;

            case 'like':
            case 'contains':
                $query->$queryMethod($field, 'LIKE', "%{$value}%");
                break;

            case 'not_like':
                $query->$queryMethod($field, 'NOT LIKE', "%{$value}%");
                break;

            case 'starts_with':
                $query->$queryMethod($field, 'LIKE', "{$value}%");
                break;

            case 'ends_with':
                $query->$queryMethod($field, 'LIKE', "%{$value}");
                break;

            case 'in':
                $values = is_array($value) ? $value : explode(',', $value);
                $query->$queryMethod(function ($q) use ($field, $values) {
                    $q->whereIn($field, $values);
                });
                break;

            case 'not_in':
                $values = is_array($value) ? $value : explode(',', $value);
                $query->$queryMethod(function ($q) use ($field, $values) {
                    $q->whereNotIn($field, $values);
                });
                break;

            case 'is_null':
                $query->$queryMethod(function ($q) use ($field) {
                    $q->whereNull($field);
                });
                break;

            case 'is_not_null':
                $query->$queryMethod(function ($q) use ($field) {
                    $q->whereNotNull($field);
                });
                break;

            case 'between':
                $values = is_array($value) ? $value : explode(',', $value);
                if (count($values) === 2) {
                    $query->$queryMethod(function ($q) use ($field, $values) {
                        $q->whereBetween($field, [$values[0], $values[1]]);
                    });
                }
                break;

            default:
                // Default to equals
                $query->$queryMethod($field, '=', $value);
                break;
        }
        return $query;
    }

    // Convert to API format
    public function toApiFormat($moduleName = ''): array
    {
        $crmField = CrmField::where('modulename', $moduleName)
                    ->where('fieldname', $this->field_name)
                    ->where('displaytype', 1)
                    ->value('fieldtype');
        return [
            'id' => $this->id,
            'field_name' => $this->field_name,
            'operator_key' => $this->operator_key,
            'operator_label' => $this->getOperator(),
            'value' => $this->value,
            'condition_type' => $this->condition_type,
            'field_type' => $crmField ?? 'string'
        ];
    }
}