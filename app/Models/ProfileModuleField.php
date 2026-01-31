<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileModuleField extends Model
{
    protected $table = 'profile_module_fields';

    protected $fillable = [
        'profileid',
        'organization_id',
        'modulename',
        'field_id',
        'invisible',
        'readonly',
        'editable',
    ];

    public $timestamps = false;

    /**
     * Optional: relationship to profile (if needed)
     */
    public function profile()
    {
        return $this->belongsTo(Profile::class, 'profileid');
    }

    /**
     * Optional: helper to check field permission
     */
    public function canView(): bool
{
    return $this->invisible == 0;
}



    public function canEdit()
    {
        return (bool) $this->editable;
    }

    public function isReadonly()
    {
        return (bool) $this->readonly;
    }
}
