<?php

// App\Models\PortalModule.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalModule extends Model
{
    protected $table = 'portal_module';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'is_entity' => 'boolean',
    ];
}

