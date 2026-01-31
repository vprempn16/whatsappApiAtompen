<?php

namespace App\Http\Filters\V1;

use App\Http\Filters\V1\QueryFilter;

class ProfileFilter extends QueryFilter
{
    protected $sortable = [
        'name',
        'status',
        //'organizationId' => 'organization_id',
        'createdAt' => 'created_at',
        'updatedAt' => 'updated_at',
        'deleted' =>'deleted'
    ];

    /**
     * Include related data, e.g., 'user' or 'organization'.
     */
    public function include($value)
    {
        return $this->builder->with($value);
    }

    /**
     * Filter profiles by name (wildcard search supported).
     */
    public function name($value)
    {
        $likeStr = str_replace('*', '%', $value);
        return $this->builder->where('name', 'like', $likeStr);
    }

    /**
     * Filter profiles by status.
     */
    public function status($value)
    {
        return $this->builder->where('status', $value);
    }

    /**
     * Filter profiles by organization ID.
     */
    public function organizationId($value)
    {
        return $this->builder->where('organization_id', $value);
    }

    /**
     * Filter profiles by the created_at date or a range of dates.
     */
    public function createdAt($value)
    {
        $dates = explode(',', $value);

        if (count($dates) > 1) {
            return $this->builder->whereBetween('created_at', $dates);
        }

        return $this->builder->whereDate('created_at', $value);
    }

    /**
     * Filter profiles by the updated_at date or a range of dates.
     */
    public function updatedAt($value)
    {
        $dates = explode(',', $value);

        if (count($dates) > 1) {
            return $this->builder->whereBetween('updated_at', $dates);
        }

        return $this->builder->whereDate('updated_at', $value);
    }
}

