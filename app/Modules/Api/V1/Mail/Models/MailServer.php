<?php

namespace App\Modules\Api\V1\Mail\Models;

use Illuminate\Database\Eloquent\Model;

class MailServer extends Model{
	protected $table = 'mail_servers';
	public $incrementing = false;
	protected $keyType = 'string';

	protected $fillable = [
		'id',
		'name',
		'description',
		'organization_id',
		'created_by',
		'mail_type',
		'host',
		'port',
		'username',
		'password',
		'encryption',
		'is_active',
		'from_name',
		'from_email',
		'folder',
		'created_at',
		'updated_at',
		'deleted'
	];

	protected static function boot()
	{
		parent::boot();
		static::creating(function ($model) {
			if (!$model->id) {
				$model->id = (string) Str::uuid();
			}
		});
	}
}
