<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log'; // exact table name

    protected $primaryKey = 'audit_id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'event_type',
        'entity_name',
        'entity_id',
        'related_entity_name',
        'related_entity_id',
        'action_by',
        'action_timestamp',
        'action_details',
        'old_value',
        'new_value',
        'ip_address',
        'user_agent',
        'organization_id',
        'more_info',
    ];

    protected $casts = [
        'action_timestamp' => 'datetime',
        'more_info' => 'array',
    ];
}

