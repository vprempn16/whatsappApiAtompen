<?php

namespace App\Modules\Api\V1\AISearch\Models;

use App\Models\AtomModel;
use Illuminate\Database\Eloquent\Model;


class AiGeneratedQueryOrgRel extends Model
{
    protected $table = 'ai_generated_queries_org_rel';

protected $primaryKey = 'id';
    public $incrementing = false;  
    protected $keyType = 'string'; 

    protected $fillable = [
        'id',            
        'query_id',
        'organization_id',
        'user_id',
        'more_info',
    ];
protected $casts = [
    'more_info' => 'array', // Laravel will auto-cast JSON string <-> array
];
     public function generatedQuery()
    {
        return $this->belongsTo(AiGeneratedQuery::class, 'query_id');
    }

    public function getModuleName(): string
    {
        return 'AiGeneratedQueryOrgRel';
    }
}

