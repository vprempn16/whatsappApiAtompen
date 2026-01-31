<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmDefaultFieldDefinition extends Model
{
    /**
     * Table name
     */
    protected $table = 'crm_default_field_definitions';

    /**
     * Mass assignable fields
     */
    protected $fillable = [
        'organization_id',
        'modulename',
        'fieldname',
        'fieldlabel',
        'mandatory',
        'seq',
    ];

    /**
     * Casts
     */
    protected $casts = [
        'mandatory' => 'boolean',
        'seq'       => 'integer',
    ];

    /**
     * Disable guarded timestamps handling
     * (Laravel will still auto-fill created_at / updated_at)
     */
    public $timestamps = true;

    /**
     * Scope: Organization safe
     */
    public function scopeForOrganization($query, ?string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: Module + Field
     */
    public function scopeForField($query, string $module, string $field)
    {
        return $query
            ->where('modulename', $module)
            ->where('fieldname', $field);
    }
}
