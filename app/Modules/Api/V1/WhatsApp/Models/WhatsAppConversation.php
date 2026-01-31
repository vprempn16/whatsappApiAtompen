<?php
namespace App\Modules\Api\V1\WhatsApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    /** CRM-style primary key */
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'organization_id',
        'conversation_id',
        'phone_number',
        'contact_id',
        'status',
        'last_message_at',
        'created_by',
        'deleted',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     * A conversation has many messages
     */
    public function messages(): HasMany
    {
        return $this->hasMany(
            WhatsAppMessage::class,
            'conversation_id',
            'id'
        );
    }
}


