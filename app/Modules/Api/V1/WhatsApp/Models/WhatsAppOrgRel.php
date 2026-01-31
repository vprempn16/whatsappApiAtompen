<?php

namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppOrgRel extends Model
{
    protected $table = 'whatsapp_org_rel';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
        'app_id',
        'app_secret',
        'phone_number_id',
        'business_id',
        'access_token',
	'created_at',
	'updated_at'
    ];
}
