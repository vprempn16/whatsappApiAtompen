<?php

namespace App\Modules\Api\V1\Zapier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ZapierImportBatch extends Model
{
    protected $table = 'zapier_import_batches';
    
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
        'module',
        'external_source',
        'sync_mode',
        'status',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Mark batch as started
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark batch as completed
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark batch as failed
     */
    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Check if batch is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if batch is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if batch failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get duration in seconds
     */
    public function getDuration(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $endTime = $this->completed_at ?? now();
        return $this->started_at->diffInSeconds($endTime);
    }

    /**
     * Scope: Get batches for organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: Get batches for module
     */
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Scope: Get running batches
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope: Get failed batches
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Relationship: Get request logs for this batch
     */
    public function requestLogs()
    {
        $query = $this->hasMany(ZapierRequestLog::class, 'organization_id', 'organization_id')
            ->where('module', $this->module)
            ->where('external_source', $this->external_source)
            ->where('sync_mode', $this->sync_mode);
        
        // Only add date filter if created_at is not null
        if ($this->created_at) {
            $query->whereDate('created_at', $this->created_at->format('Y-m-d'));
        }
        
        return $query;
    }
}
