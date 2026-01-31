<?php

namespace App\Modules\Api\V1\Zapier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ZapierWebhookCache extends Model
{
    protected $table = 'zapier_webhook_caches';
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
        'batch_id',
        'organization_id',
        'module',
        'external_source',
        'external_id',
        'record_index',
        'status',
        'raw_payload',
        'mapping',
        'mapped_payload',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'mapping' => 'array',
        'mapped_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(ZapierImportBatch::class, 'batch_id', 'id');
    }

    public function scopeForBatch($query, string $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeMapped($query)
    {
        return $query->where('status', 'mapped');
    }

    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
