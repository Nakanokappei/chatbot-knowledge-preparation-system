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
 * Published packages are immutable — use newVersion() to create an editable copy.
 * Retrieval is only performed against published packages.
 *
 * DB table: knowledge_datasets (renamed in Phase 3)
 */
class KnowledgePackage extends Model
{
    use BelongsToWorkspace;

    // Explicit table name until Phase 3 DB rename
    protected $table = 'knowledge_datasets';

    protected $fillable = [
        'workspace_id', 'name', 'description', 'version', 'status',
        'source_job_ids', 'ku_count', 'created_by',
    ];

    protected $casts = [
        'source_job_ids' => 'array',
    ];

    /**
     * Only published packages are available for retrieval/chat.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if the package is awaiting publication authorization.
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
     * Check if this package can be submitted for publication (must be draft).
     */
    public function isSubmittable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if this package can be authorized for publication by an owner (must be pending_review).
     */
    public function isApprovable(): bool
    {
        return $this->status === 'pending_review';
    }

    /** The KU items included in this package, ordered by sort position. */
    public function items(): HasMany
    {
        // FK column name uses legacy 'knowledge_dataset_id' until Phase 3 DB rename
        return $this->hasMany(KnowledgePackageItem::class, 'knowledge_dataset_id')->orderBy('sort_order');
    }

    /** The user who created this package. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
