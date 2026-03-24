<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat conversation scoped to a published Knowledge Dataset.
 *
 * Minimal implementation per CTO directive — Phase 4 is a RAG verification API,
 * not a full conversational UI.
 */
class ChatConversation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'knowledge_dataset_id', 'user_id',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function knowledgeDataset(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDataset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
