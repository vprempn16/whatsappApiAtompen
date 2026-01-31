<?php


namespace App\Modules\Api\V1\AISearch\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\AtomModel;

class AiGeneratedQuery extends Model
{
    protected $table = 'ai_generated_queries';

    // Configure for UUID primary keys
    protected $primaryKey = 'id';
    public $incrementing = false;  
    protected $keyType = 'string'; 

    protected $fillable = [
        'id',        
        'prompt',
        'query',
        'context',
        'user_id',
        'deleted',
        'more_info',
    ];

    protected $casts = [
        'more_info' => 'array', // Laravel will auto-cast JSON string <-> array
    ];

    /**
     * Relationship to organization query relations
     */
    public function orgRelations()
    {
        return $this->hasMany(AiGeneratedQueryOrgRel::class, 'query_id');
    }

    /**
     * Return module name
     */
    public function getModuleName(): string
    {
        return 'AiGeneratedQuery';
    }
}
