<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat session scoped to a workspace embedding (workspace overlay chat).
 *
 * One session per conversation thread. The title is derived from the first
 * user message and is displayed in the session history sidebar.
 */
class ChatSession extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'user_id', 'embedding_id', 'title',
    ];

    /** All turns in this session, ordered chronologically. */
    public function turns(): HasMany
    {
        return $this->hasMany(ChatTurn::class, 'session_id')->orderBy('created_at');
    }

    /** The embedding this session queries against. */
    public function embedding(): BelongsTo
    {
        return $this->belongsTo(Embedding::class);
    }

    /** The user who initiated this session. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
