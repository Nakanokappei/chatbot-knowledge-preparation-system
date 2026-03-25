<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An individual message within a chat conversation.
 *
 * Tracks role (user/assistant), content, source KUs, and token usage
 * for cost analysis and retrieval quality evaluation.
 */
class ChatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'role', 'content', 'sources_json',
        'input_tokens', 'output_tokens', 'latency_ms',
    ];

    protected $casts = [
        'sources_json' => 'array',
        'created_at' => 'datetime',
    ];

    /** The conversation this message belongs to. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
