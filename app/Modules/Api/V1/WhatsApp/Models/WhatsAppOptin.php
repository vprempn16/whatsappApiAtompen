<?php
namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppOptin extends Model
{
    protected $table = 'whatsapp_optins';

    protected $fillable = [
        'phone_number',
        'opted_in',
        'opted_in_at',
        'opted_out_at',
    ];

    protected $casts = [
        'opted_in'     => 'boolean',
        'opted_in_at'  => 'datetime',
        'opted_out_at' => 'datetime',
    ];
}

