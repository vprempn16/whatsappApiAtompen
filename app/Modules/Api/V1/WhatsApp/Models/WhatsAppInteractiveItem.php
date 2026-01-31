<?php 
namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppInteractiveItem extends Model {
    public $incrementing = false;
     protected $table = 'whatsapp_interactive_items';
    protected $keyType = 'string';
    protected $fillable = [
        'id','interactive_id','organization_id','item_type','item_key','title','description',
        'section','sort_order','next_action_type','next_action_value'
    ];
}

