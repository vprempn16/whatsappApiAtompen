<?php


namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppInteractive extends Model
{
	//
	public $incrementing = false;
	protected $table = 'whatsapp_interactives';
	protected $keyType = 'string';

	protected $fillable = [
		'id','organization_id','whatsapp_channel_id','name','type','body',
		'crm_module','trigger_event','is_active','created_by'
	];

	public function items(){
		return $this->hasMany(WhatsAppInteractiveItem::class,'interactive_id');
	}
	protected $casts = [
		'structure_json' => 'array'
	];
}

