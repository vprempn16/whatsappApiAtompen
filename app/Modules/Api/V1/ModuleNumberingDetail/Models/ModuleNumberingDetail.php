<?php

namespace App\Modules\Api\V1\ModuleNumberingDetail\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleNumberingDetail extends Model
{
    protected $table = 'module_numbering_details';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
        'module_name',
        'prefix',
        'initial_suffix',
        'current_suffix',
        'created_at',
        'updated_at'
    ];

    public $timestamps = true;
}

