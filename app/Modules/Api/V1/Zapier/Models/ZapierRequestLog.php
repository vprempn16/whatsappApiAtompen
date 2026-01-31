<?php

namespace App\Modules\Api\V1\Zapier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ZapierRequestLog extends Model
{
    protected $table = 'zapier_request_logs';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    
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
        'module',
        'external_source',
        'external_id',
        'sync_mode',
        'status',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Check if log is successful
     */
    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if log is failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Scope: Get logs for organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: Get logs for module
     */
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope: Get successful logs
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * Scope: Get failed logs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope: Get logs by external_id
     */
    public function scopeByExternalId($query, string $externalId)
    {
        return $query->where('external_id', $externalId);
    }

    /**
     * Scope: Get logs for external source
     */
    public function scopeForExternalSource($query, string $externalSource)
    {
        return $query->where('external_source', $externalSource);
    }

    /**
     * Check if external_id already exists (successful import)
     */
    public static function existsForExternalId(string $organizationId, string $module, string $externalId): bool
    {
        return self::where('organization_id', $organizationId)
            ->where('module', $module)
            ->where('external_id', $externalId)
            ->where('status', 'success')
            ->exists();
    }

    /**
     * Get existing log for external_id
     */
    public static function findByExternalId(string $organizationId, string $module, string $externalId): ?self
    {
        return self::where('organization_id', $organizationId)
            ->where('module', $module)
            ->where('external_id', $externalId)
            ->where('status', 'success')
            ->first();
    }
}
