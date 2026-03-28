<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user/assistant turn within a ChatSession (workspace embedding chat).
 *
 * Sources and context are stored as JSON so conversation history can be
 * replayed with full citation information. search_mode and extracted_slots
 * provide retrieval analytics per turn.
 */
class ChatTurn extends Model
{
    protected $fillable = [
        'session_id', 'role', 'content', 'sources', 'context', 'action',
        'search_mode', 'extracted_slots',
    ];

    protected $casts = [
        'sources' => 'array',
        'context' => 'array',
        'extracted_slots' => 'array',
    ];

    /** The session this turn belongs to. */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
