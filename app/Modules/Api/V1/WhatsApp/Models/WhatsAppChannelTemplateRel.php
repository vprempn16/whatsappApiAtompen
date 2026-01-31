<?php
namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppChannelTemplateRel extends Model
{
       protected $table = 'whatsapp_channel_template_rel';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'whatsapp_channels_id',
        'whatsapp_template_id',
        'created_at',
        'updated_at',
    ];
}

