<?php

namespace App\Modules\Api\V1\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WorkflowActionTypeModuleRel extends Model
{
    protected $table = 'workflow_actiontype_module_rel';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'action_type_id',
        'module_id',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function actionType()
    {
        return $this->belongsTo(WorkflowActionType::class, 'action_type_id');
    }

    public function module()
    {
        return $this->belongsTo(\App\Models\PortalModule::class, 'module_id');
    }
}
