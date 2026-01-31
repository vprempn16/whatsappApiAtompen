<?php
namespace App\Modules\Api\V1\Mail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MailLog extends Model
{
	   protected $table = 'mail_logs';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
        'info' => 'array',
    ];

    protected $fillable = [
        'id',
        'organization_id',
        'mail_server_id',
        'created_by',
        'direction',
        'to_email',
        'from_email',
        'subject',
        'body',
        'imap_uid',
        'message_id',
        'in_reply_to',
        'references',
        'thread_id',
        'opened_at',
        'tracking_token',
        'is_read',
        'status',
        'error_message',
        'info',
        'created_at',
        'updated_at',
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
}
