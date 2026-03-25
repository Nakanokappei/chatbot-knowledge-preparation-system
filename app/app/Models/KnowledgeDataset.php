<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned collection of approved Knowledge Units for chatbot consumption.
 *
 * Published datasets are immutable — use newVersion() to create an editable copy.
 * Retrieval is only performed against published datasets.
 */
class KnowledgeDataset extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'version', 'status',
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
     * Published and archived datasets cannot be modified.
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
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
