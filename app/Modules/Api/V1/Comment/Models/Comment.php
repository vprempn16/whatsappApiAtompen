<?php

namespace App\Modules\Api\V1\Comment\Models;

use App\Models\AtomModel;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Comment extends AtomModel
{
    protected $fillable = [
        'content',
        'created_by',
        //'organization_id',
        'deleted',
    ];

    /**
     * Get the relation record for the comment.
     */
    public function rel(): HasOne
    {
        return $this->hasOne(CommentRel::class);
    }

    /**
     * Get all of the comment's child relations.
     * These relations point to comments that are replies to this one.
     * To get the reply comments, you can do $comment->childRels->load('comment').
     */
    public function childRels(): MorphMany
    {
        return $this->morphMany(CommentRel::class, 'parent', 'parent_module', 'parent_id');
    }

    /**
     * Get all of the comment's relations.
     */
    public function relations()
    {
        return $this->hasMany(CommentRel::class);
    }
}
