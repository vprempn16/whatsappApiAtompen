<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Filter extends Model
{
    public $incrementing = false;
    protected $table = 'filters';
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['deleted','organization_id','created_by'];
    protected $casts = [
        'is_shared' => 'boolean',
        'is_default' => 'boolean',
        'deleted' => 'boolean',
        'header_details' => 'array',
    ];


    protected static function booted()
    {
        static::creating(function ($model) {
            if (!isset($model->id)) {
                $model->id = (string) Str::uuid();
            }
            $model->created_by = auth()->user()->id;
            $model->organization_id = auth()->user()->organization_id ?? null;
            $model->created_at = now();
        });

        static::deleting(function ($filter) {
            // Delete associated conditions
            $filter->conditions()->delete();
        });
    }

    // Relationships
    public function conditions()
    {
        return $this->hasMany(FilterCondition::class, 'filter_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Modules\Api\V1\User\Models\User::class, 'id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
    // Scopes
    public function scopeForModule($query, string $moduleName)
    {
        return $query->where('module_name', $moduleName);
    }

    public function scopeActive($query)
    {
        return $query->where('deleted', 0);
    }
    public function scopeForUser($query, $userId = null)
    {
        $userId = $userId ?? auth()->user()->id;
        return $query->where(function ($q) use ($userId) {
            $q->where('created_by', $userId)
              ->orWhere('is_shared', 1);
        });
    }

    public function scopeForOrganization($query, $organizationId = null)
    {
        $organizationId = $organizationId ?? auth()->user()->organization_id;
        return $query->where('organization_id', $organizationId);
    }

    // Methods
    public function setAsDefault(): bool
    {
        DB::beginTransaction();
        try {
            // Remove default from other filters in the same module
            static::where('module_name', $this->module_name)
                ->where('organization_id', $this->organization_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => 0]);

            $this->is_default = 1;
            $this->save();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to set filter as default: {$e->getMessage()}");
            return false;
        }
    }

    public function duplicate(string $newName = null): ?Filter
    {
        DB::beginTransaction();
        try {
            $newFilter = $this->replicate();
            $newFilter->id = (string) Str::uuid();
            $newFilter->name = $newName ?? ($this->name . ' (Copy)');
            $newFilter->is_default = 0;
            $newFilter->created_by = auth()->user()->id;
            $newFilter->created_at = now();
            $newFilter->save();

            // Duplicate conditions
            foreach ($this->conditions as $condition) {
                $newCondition = $condition->replicate();
                $newCondition->filter_id = $newFilter->id;
                $newCondition->save();
            }

            DB::commit();
            return $newFilter;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to duplicate filter: {$e->getMessage()}");
            return null;
        }
    }

    public function softDelete(): bool
    {
        $this->deleted = 1;
        $this->updated_at = now();
        return $this->save();
    }

    public function toApiFormat(): array
{
    return [
        'id'             => $this->id,
        'name'           => $this->name,
        'description'    => $this->description,
        'module_name'    => $this->module_name,

        'is_shared'      => (bool) $this->is_shared,
        'is_default'     => (bool) $this->is_default,

        'header_details' => $this->header_details,
        'created_by'     => $this->created_by,
        'creator_name'   => optional($this->creator)->name,

        'conditions'     => $this->relationLoaded('conditions')
                            ? $this->conditions->map(fn ($c) => $c->toApiFormat())
                            : [],

        'created_at'     => $this->created_at?->format('d M Y'),
        'updated_at'     => $this->updated_at?->format('d M Y'),
    ];
}

    public static function getForModule(string $moduleName)
{
    $userId = auth()->user()->id;
    if (!$userId) {
        return null;
    }
    return self::query()->forModule($moduleName)
        ->forOrganization()
        ->forUser($userId)
        ->active()
        ->with(['conditions', 'creator'])
        ->get();
}
    public static function createWithConditions(array $data): ?Filter
    {
        DB::beginTransaction();
        try {
            $filter = new static();
            $filter->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'module_name' => $data['module_name'],
                'is_shared' => $data['is_shared'] ?? false,
                'is_default' => $data['is_default'] ?? false,
                'header_details' => $data['header_details'] ?? null,                
            ]);
            $filter->save();

            if (!empty($data['conditions'])) {
                foreach ($data['conditions'] as $condition) {
                    FilterCondition::create([
                        'filter_id' => $filter->id,
                        'field_name' => $condition['field_name'],
                        'operator_key' => $condition['operator_key'],
                        'value' => $condition['value'] ?? null,
                        'condition_type' => $condition['condition_type'] ?? 'AND',
                    ]);
                }
            }

            if ($filter->is_default) {
                $filter->setAsDefault();
            }

            DB::commit();
            return $filter;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to create filter: {$e->getMessage()}");
            throw $e;
        }
    }

    public function updateWithConditions(array $data): bool
    {
        DB::beginTransaction();
        try {
            $this->fill([
                'name' => $data['name'] ?? $this->name,
                'description' => $data['description'] ?? $this->description,
                'is_shared' => $data['is_shared'] ?? $this->is_shared,
                'is_default' => $data['is_default'] ?? $this->is_default,
                'header_details' => $data['header_details'] ?? $this->header_details,
                
            ]);
            $this->save();

            if (isset($data['conditions'])) {
                // Delete existing conditions
                $this->conditions()->delete();

                // Create new conditions
                foreach ($data['conditions'] as $condition) {
                    FilterCondition::create([
                        'filter_id' => $this->id,
                        'field_name' => $condition['field_name'],
                        'operator_key' => $condition['operator_key'],
                        'value' => $condition['value'] ?? null,
                        'condition_type' => $condition['condition_type'] ?? 'AND',
                    ]);
                }
            }

            if ($this->is_default) {
                $this->setAsDefault();
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to update filter: {$e->getMessage()}");
            throw $e;
        }
    }
}