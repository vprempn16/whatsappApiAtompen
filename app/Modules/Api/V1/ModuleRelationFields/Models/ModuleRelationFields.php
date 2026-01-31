<?php

namespace App\Modules\Api\V1\ModuleRelationFields\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleRelationFields extends Model
{
    protected $table = 'module_relation_fields';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'field_id',
        'modulename',
        'related_module',
        'deleted',
        'created_at',
        'updated_at'
    ];

    public $timestamps = true;
}
