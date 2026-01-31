<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileField extends Model
{
    protected $table = 'profile_fields';
    public $timestamps = false;
    protected $fillable = ['organization_id', 'profileid', 'modulename', 'fieldid', 'invisible', 'readonly'];
}
