<?php

namespace App\Modules\Api\V1\ActivityRelation\Models;

use App\Models\AtomModel;
use App\Modules\Api\V1\Activity\Models\Activity;

class ActivityRelation extends AtomModel
{
    public function activity()
    {
        return $this->belongsTo(Activity::class, 'activity_id', 'id');
    }
}
