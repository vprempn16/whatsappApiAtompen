<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleProfileRel extends Model
{
    protected $table = 'role_profile_rel';
    public $timestamps = false;
    protected $fillable = ['role_id',  'profile_id', 'organization_id'];
}
