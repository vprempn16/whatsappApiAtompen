<?php

namespace App\Modules\Api\V1\Workflow\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WorkflowActionType extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'workflow_action_types';

    protected $fillable = [
        'organization_id',
        'action_label',
        'action_type',
        'module_name',
        'function_path',
        'description',
    ];
}
