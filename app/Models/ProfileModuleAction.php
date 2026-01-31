<?php


namespace App\Models;


use Illuminate\Database\Eloquent\Model;


class ProfileModuleAction extends Model
{
protected $table = 'profile_module_actions';
protected $fillable = ['profileid', 'organization_id', 'modulename', 'action_id', 'permission'];


public $timestamps = true;


public function action()
{
return $this->belongsTo(SystemAction::class, 'action_id');
}
}
