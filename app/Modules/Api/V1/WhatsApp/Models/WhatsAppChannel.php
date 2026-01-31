<?php 

namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppChannel extends Model
{
    protected $table = 'whatsapp_channels';
 	public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
        'name',
        'desc',
        'app_id',
        'app_secret',
        'phone_number_id',
        'business_id',
        'access_token',
        'is_active',
        'created_by'
    ];
}

