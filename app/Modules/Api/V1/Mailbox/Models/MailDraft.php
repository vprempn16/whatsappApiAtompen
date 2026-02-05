<?php
namespace App\Modules\Api\V1\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MailDraft extends Model
{
    protected $table = 'mail_drafts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id', 'user_id', 'mail_server_id',
        'to', 'cc', 'bcc', 'subject', 'body',
        'reply_to_mail_log_id', 'forward_from_mail_log_id',
        'related_module', 'related_record_id', 'deleted'
    ];

    protected $casts = [
        'to' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
