<?php

namespace App\Modules\Api\V1\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MailRelation extends Model
{
    protected $table = 'mail_relations';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'organization_id',
        'module',
        'record_id',
        'mail_log_id',
        'created_by',
        'created_at',
        'deleted'
    ];

    
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function mailLog()
    {
        return $this->belongsTo(MailLog::class, 'mail_log_id');
    }
}
