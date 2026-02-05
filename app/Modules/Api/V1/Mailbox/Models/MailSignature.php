<?php
namespace App\Modules\Api\V1\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MailSignature extends Model
{
    protected $table = 'mail_signatures';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'organization_id', 'user_id',
        'name', 'content', 'is_default', 'deleted'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
}
