<?php

namespace App\Modules\Api\V1\RelatedRecords\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ModuleRelationField extends Model
{
    use HasUuids;

    protected $table = 'module_relation_fields';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'field_id',
        'modulename',
        'related_module',
        'deleted',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'deleted' => 'boolean',
    ];
}
