<?php

namespace App\Modules\Api\V1\AISearch\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\AtomModel;


class AiTableMeta extends AtomModel
{
    protected $table = 'ai_table_meta';

    protected $guarded = [];

    protected $casts = [
        'relationships' => 'array',
    ];
}
