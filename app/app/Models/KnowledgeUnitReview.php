<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records a human review action on a Knowledge Unit.
 * Each status transition is stored as an immutable entry.
 */
class KnowledgeUnitReview extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'knowledge_unit_id', 'reviewer_user_id',
        'review_status', 'review_comment', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function knowledgeUnit(): BelongsTo
    {
        return $this->belongsTo(KnowledgeUnit::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
