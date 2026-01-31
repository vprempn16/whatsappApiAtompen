<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmField extends Model
{
    protected $table = 'crm_fields';

    public $incrementing = false;
    protected $keyType = 'string';
    protected $hidden = ['organization_id','created_at','updated_at'];

    protected $fillable = [
        'id',
        'organization_id',
        'modulename',
        'fieldname',
        'fieldlabel',
        'fieldtype',
        'tablename',
        'mandatory',
        'apifieldname',
        'is_custom_field',
        'created_at',
        'updated_at',
	'seq','deleted'
    ];

    public function scopeDefault($query)
    {
        return $query->where('organization_id', auth()->user()->organization_id);
    }
}

