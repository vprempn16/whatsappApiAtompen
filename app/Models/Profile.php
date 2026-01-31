<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Profile extends Model
{
    use HasFactory;

    protected $table = 'profiles';

    protected $fillable = [
        'id',
        'organization_id',
        'name',
        'description',
        'status',
        'deleted'
    ];
    public function scopeFilter(Builder $builder, $filters)
        {
                return $filters->apply($builder);
        }
}

