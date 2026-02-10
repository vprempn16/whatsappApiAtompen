<?php
namespace App\Modules\Api\V1\Mail\Models;

use Illuminate\Database\Eloquent\Model;

class MailImapServer extends Model
{
    protected $table = 'mail_imap_servers';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'description',
        'organization_id',
        'mail_server_id',
        'created_by',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'folder',
        'last_uid',
        'min_uid',
        'last_sync_at',
        'is_active',
        'deleted'
    ];
}
