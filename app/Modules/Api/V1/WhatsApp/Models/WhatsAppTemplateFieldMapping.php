<?php

namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppTemplateFieldMapping extends Model
{
	protected $table = 'whatsapp_template_field_mappings';

	public $incrementing = false;
	protected $keyType = 'string';

	protected $fillable = [
		'id',
		'organization_id',
		'template_id',
		'template_language',
		'module',
		'template_variable',
		'component_type',
		'button_index',
		'button_param_position',
		'crm_module',
		'crm_field'
	];

	protected static function boot()
	{
		parent::boot();

		static::creating(function ($model) {
			if (empty($model->id)) {
				$model->id = (string) Str::uuid();
			}
		});
	}
}
