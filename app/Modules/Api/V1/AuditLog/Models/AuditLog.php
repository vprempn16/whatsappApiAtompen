<?php

namespace App\Modules\Api\V1\AuditLog\Models;

use App\Models\AtomModel;

class AuditLog extends AtomModel
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
}
