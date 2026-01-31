<?php

namespace App\Modules\Api\V1\Zapier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ZapierConnectedApp extends Model
{
    protected $table = 'zapier_connected_apps';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'external_source',
        'modules',
        'last_synced_at',
    ];

    protected $casts = [
        'modules' => 'array',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Check if module is enabled for this app
     */
    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? []);
    }

    /**
     * Update last synced timestamp
     */
    public function updateLastSynced(): void
    {
        $this->update(['last_synced_at' => now()]);
    }

    /**
     * Scope: Get apps for organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: Get app by external source
     */
    public function scopeByExternalSource($query, string $externalSource)
    {
        return $query->where('external_source', $externalSource);
    }

    /**
     * Find or create connected app
     */
    public static function findOrCreate(string $organizationId, string $externalSource, array $modules = []): self
    {
        return self::firstOrCreate(
            [
                'organization_id' => $organizationId,
                'external_source' => $externalSource,
            ],
            [
                'modules' => $modules,
            ]
        );
    }
}
