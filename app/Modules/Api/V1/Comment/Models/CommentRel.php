<?php

namespace App\Modules\Api\V1\Comment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\AtomModel;

class CommentRel extends AtomModel
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'comment_rel';

    /**
     * Get the parent model (e.g. a post or another comment).
     */
    public function parent(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the comment that owns the relation.
     */
    public function comment()
    {
        return $this->belongsTo(Comment::class);
    }
}
