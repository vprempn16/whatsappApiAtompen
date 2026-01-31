<?php

namespace App\Modules\Api\V1\SearchableModule\Models;

use Illuminate\Database\Eloquent\Model;

class SearchableModule extends Model
{
    protected $fillable = ['module_name', 'searchable_field'];
}

