<?php

namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplate extends Model
{	
       protected $table = 'whatsapp_templates';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
	'business_id',
	'whatsapp_channel_id',
	'module',
	'created_by',
	'template_name',
        'language',
        'format',
        'status',
        'components',
        'category',
        'template_id',
    ];

    protected $casts = [
        'components' => 'array',
    ];
}

