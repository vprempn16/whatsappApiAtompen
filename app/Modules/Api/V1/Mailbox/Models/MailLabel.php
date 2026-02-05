<?php
namespace App\Modules\Api\V1\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Api\V1\Mail\Models\MailLog;

class MailLabel extends Model
{
    protected $table = 'mailbox_labels';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id', 'user_id',
        'name', 'color', 'description', 'deleted'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function emails()
    {
        return $this->belongsToMany(
            MailLog::class, 
            'mail_email_labels', 
            'label_id', 
            'mail_log_id'
        );
    }
}
