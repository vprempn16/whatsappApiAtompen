<?php
namespace App\Modules\Api\V1\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Modules\Api\V1\Mail\Models\MailLog;

class MailboxFolder extends Model
{
    protected $table = 'mailbox_folders';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id','created_by', 'user_id', 'mail_server_id',
        'name', 'slug', 'type', 'icon', 'sort_order',
        'is_default', 'deleted', 'last_uid','min_uid','last_sync_at'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function emails()
    {
        return $this->hasMany(MailLog::class, 'folder_id');
    }
}
