<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat conversation scoped to a published Knowledge Package.
 *
 * Minimal implementation per CTO directive — Phase 4 is a RAG verification API,
 * not a full conversational UI.
 */
class ChatConversation extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $fillable = [
        'workspace_id', 'knowledge_dataset_id', 'user_id',
    ];

    /** Get all messages in this conversation, ordered chronologically. */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }

    /** The published knowledge package this conversation queries against. */
    public function knowledgePackage(): BelongsTo
    {
        // FK column uses legacy 'knowledge_dataset_id' until Phase 3 DB rename
        return $this->belongsTo(KnowledgePackage::class, 'knowledge_dataset_id');
    }

    /** The user who initiated this conversation. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
