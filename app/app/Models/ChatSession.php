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
 *
 * Conversation state (slot-filling progress) is persisted server-side so
 * the chat can resume after page reloads without replaying turns.
 */
class ChatSession extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'user_id', 'embedding_id', 'title',
        'current_primary_filter', 'current_question', 'state',
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

    /**
     * Build the slot-filling context object from persisted state.
     *
     * Returns the same structure previously managed client-side:
     * { primary_filter, question }
     */
    public function buildContext(): array
    {
        return [
            'primary_filter' => $this->current_primary_filter,
            'question' => $this->current_question,
        ];
    }

    /**
     * Update conversation state from RagService result.
     *
     * Accepts the context array returned by the service layer and
     * persists the slot values and state machine step to the database.
     */
    public function updateFromResult(array $context, string $action): void
    {
        $this->current_primary_filter = $context['primary_filter'] ?? $this->current_primary_filter;
        $this->current_question = $context['question'] ?? $this->current_question;

        // Derive state from the action returned by RagService
        $this->state = match ($action) {
            'ask_primary_filter' => 'waiting_for_filter',
            'answer', 'answer_broad' => 'answered',
            'rejected', 'no_match' => 'idle',
            default => $this->state,
        };

        $this->save();
    }

    /**
     * Reset conversation state for a fresh topic within the same session.
     */
    public function resetState(): void
    {
        $this->update([
            'current_primary_filter' => null,
            'current_question' => null,
            'state' => 'idle',
        ]);
    }
}
