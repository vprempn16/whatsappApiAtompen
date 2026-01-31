<?php

namespace App\Modules\Api\V1\AISearch\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\AtomModel;
use App\Models\CrmField;


class AiColumnMeta extends AtomModel
{
    protected $table = 'ai_column_meta';

    protected $guarded = [];

    protected $casts = [
    'semantic_context' => 'array',
        'value_examples'   => 'array',
        'is_identifier'    => 'boolean',
    ];

        public function table()
    {
        return $this->belongsTo(AiTableMeta::class, 'table_id');
    }

    public function crmField()
    {
        return $this->belongsTo(CrmField::class, 'crm_field_id');
    }

}
