<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WorkflowQueue extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'workflow_queues';

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'params',
        'status',
        'priority',
        'attempts',
        'error_message',
        'scheduled_at',
        'executed_at',
        'related_module',
        'related_record_id',
    ];

    protected $casts = [
        'params' => 'array',
        'scheduled_at' => 'datetime',
        'executed_at' => 'datetime',
    ];
}
