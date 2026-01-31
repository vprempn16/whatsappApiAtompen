<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleUserRel extends Model
{
    protected $table = 'role_user_rel';
    public $timestamps = false;
    protected $fillable = ['role_id', 'organization_id', 'user_id'];
}