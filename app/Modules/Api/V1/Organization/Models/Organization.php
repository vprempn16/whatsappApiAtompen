<?php

namespace App\Modules\Api\V1\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Organization extends Model
{
    protected $table = 'organizations';
    
    /**
     * Primary key is UUID (char(36))
     */
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'id',
        'name',
        'description',
    ];

    /**
     * Timestamps enabled
     */
    public $timestamps = true;

    /**
     * ------------------------------------------------------------
     * Global scope: exclude deleted records
     * ------------------------------------------------------------
     */
    protected static function booted()
    {
        static::addGlobalScope('not_deleted', function (Builder $builder) {
            $builder->where('deleted', 0);
        });
    }

    /**
     * ------------------------------------------------------------
     * Soft delete replacement (custom flag)
     * ------------------------------------------------------------
     */
    public function softDelete(): bool
    {
        return $this->update(['deleted' => 1]);
    }

    public function restore(): bool
    {
        return $this->update(['deleted' => 0]);
    }

    /**
     * ------------------------------------------------------------
     * Relationships (optional, safe to add)
     * ------------------------------------------------------------
     */

    public function users()
    {
        return $this->hasMany(User::class, 'organization_id');
    }

    public function profiles()
    {
        return $this->hasMany(Profile::class, 'organization_id');
    }

    public function roles()
    {
        return $this->hasMany(Role::class, 'organization_id');
    }

    /**
     * Transform the model to API format
     * Required for FilterService list operations
     */
    public function transformToApiFormat(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}