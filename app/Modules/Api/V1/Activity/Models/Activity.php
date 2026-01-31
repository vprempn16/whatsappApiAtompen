<?php

namespace App\Modules\Api\V1\Activity\Models;

use App\Models\AtomModel;
use App\Modules\Api\V1\ActivityRelation\Models\ActivityRelation;

class Activity extends AtomModel
{
    public function relations()
    {
        return $this->hasMany(ActivityRelation::class, 'activity_id', 'id');
    }
}