<?php


namespace App\Models;


use Illuminate\Database\Eloquent\Model;


class SystemAction extends Model
{
protected $table = 'system_actions';
protected $fillable = ['action_key', 'label', 'security_check'];


public $timestamps = true;


public static function seedDefaults()
{
$defaults = [
['action_key' => 'view', 'label' => 'View Record', 'security_check' => 0],
['action_key' => 'create', 'label' => 'Create Record', 'security_check' => 0],
['action_key' => 'edit', 'label' => 'Edit Record', 'security_check' => 0],
['action_key' => 'delete', 'label' => 'Delete Record', 'security_check' => 0],


['action_key' => 'export', 'label' => 'Export Records', 'security_check' => 0],
['action_key' => 'import', 'label' => 'Import Records', 'security_check' => 0],
['action_key' => 'print', 'label' => 'Print Record', 'security_check' => 0],
['action_key' => 'duplicate', 'label' => 'Duplicate Record', 'security_check' => 0],
['action_key' => 'mass_edit', 'label' => 'Mass Edit', 'security_check' => 0],
['action_key' => 'archive', 'label' => 'Archive Record', 'security_check' => 0],
['action_key' => 'restore', 'label' => 'Restore Record', 'security_check' => 0],


['action_key' => 'send_email', 'label' => 'Send Email', 'security_check' => 0],
['action_key' => 'upload_file', 'label' => 'Upload Attachment', 'security_check' => 0],
['action_key' => 'download_file', 'label' => 'Download Attachment', 'security_check' => 0],
['action_key' => 'delete_file', 'label' => 'Delete Attachment', 'security_check' => 0],
];


foreach ($defaults as $d) {
static::firstOrCreate(['action_key' => $d['action_key']], $d);
}
}
}
