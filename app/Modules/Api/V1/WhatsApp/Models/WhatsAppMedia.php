<?php

namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMedia extends Model
{
    //
	 protected $table = 'whatsapp_media';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
	'whatsapp_channel_id',
        'media_id',
        'mime_type',
        'file_name',
        'local_path',
        'created_by',
	'created_at',
	'updated_at',
    ];
}
