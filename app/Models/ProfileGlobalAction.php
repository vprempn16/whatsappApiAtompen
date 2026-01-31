<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileGlobalAction extends Model
{
    protected $table = 'profile_global_actions';
    public $timestamps = false;
    protected $fillable = ['profileid', 'organization_id', 'modulename', 'operation', 'permissions'];
}