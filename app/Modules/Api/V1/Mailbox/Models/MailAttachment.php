<?php
namespace App\Modules\Api\V1\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Api\V1\Mail\Models\MailLog;

class MailAttachment extends Model
{
    protected $table = 'mail_attachments';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id', 'mail_log_id',
        'filename', 'original_filename', 'mime_type',
        'size', 'storage_path', 'storage_disk', 'deleted'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function email()
    {
        return $this->belongsTo(MailLog::class, 'mail_log_id');
    }
}
