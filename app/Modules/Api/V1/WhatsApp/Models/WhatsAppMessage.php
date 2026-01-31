<?php

namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
	  protected $table = 'whatsapp_messages';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
	    'whatsapp_channel_id',
        'message_id',
        'direction',
        'type',
        'message',
        'crm_module',
        'crm_field',
        'crm_field_value',
        'related_module',
        'conversation_key',
        'related_id',
        'status',
        'info',
        'media_id',
        'created_by',
        'deleted',
        'created_at',
        'updated_at',
    ];
}

