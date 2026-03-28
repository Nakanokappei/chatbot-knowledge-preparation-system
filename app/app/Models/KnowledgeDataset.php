<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned collection of approved Knowledge Units for chatbot consumption.
 *
 * Lifecycle: draft → pending_review → published (→ archived when superseded).
 * Published datasets are immutable — use newVersion() to create an editable copy.
 * Retrieval is only performed against published datasets.
 */
class KnowledgeDataset extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'name', 'description', 'version', 'status',
        'source_job_ids', 'ku_count', 'created_by',
    ];

    protected $casts = [
        'source_job_ids' => 'array',
    ];

    /**
     * Only published datasets are available for retrieval/chat.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if the dataset is awaiting owner approval.
     */
    public function isPendingReview(): bool
    {
        return $this->status === 'pending_review';
    }

    /**
     * Only drafts can be edited (pending_review, published, archived are immutable).
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if this dataset can be submitted for review (must be draft).
     */
    public function isSubmittable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if this dataset can be approved by an owner (must be pending_review).
     */
    public function isApprovable(): bool
    {
        return $this->status === 'pending_review';
    }

    /** The KU items included in this dataset, ordered by sort position. */
    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeDatasetItem::class)->orderBy('sort_order');
    }

    /** The user who created this dataset. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
