<?php

namespace App\Modules\Api\V1\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Workflow extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id', 'created_at', 'updated_at', 'created_by'];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
            if (empty($model->organization_id)) {
                $model->organization_id = auth()->user()?->organization_id;
            }
            if (empty($model->created_by)) {
                $model->created_by = auth()->id();
            }
        });
    }

    public function triggers()
    {
        return $this->hasMany(WorkflowTrigger::class);
    }

    public function conditions()
    {
        return $this->hasMany(WorkflowCondition::class);
    }

    public function actions()
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('execution_order');
    }
}
