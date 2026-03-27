<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stores upvote/downvote feedback on individual chat answers.
 * Links to the embedding, user, and the KU IDs that were cited.
 */
class AnswerFeedback extends Model
{
    protected $table = 'answer_feedback';

    protected $fillable = [
        'workspace_id', 'user_id', 'embedding_id',
        'vote', 'question', 'answer', 'source_ku_ids',
    ];

    protected $casts = [
        'source_ku_ids' => 'array',
    ];
}
