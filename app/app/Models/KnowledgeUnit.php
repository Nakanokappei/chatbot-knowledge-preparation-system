<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The FINAL PRODUCT of this system (Design Principle 7).
 *
 * Each Knowledge Unit represents a structured piece of knowledge
 * derived from a cluster of support log entries, ready for
 * chatbot consumption.
 */
class KnowledgeUnit extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'dataset_id', 'pipeline_job_id', 'cluster_id',
        'topic', 'intent', 'summary', 'question', 'symptoms',
        'root_cause', 'primary_filter', 'category',
        'typical_cases_json',
        'cause_summary', 'resolution_summary', 'notes',
        'representative_rows_json', 'keywords_json', 'row_count',
        'confidence', 'review_status', 'source_refs_json',
        'pipeline_config_version', 'prompt_version', 'version',
        'source_type', 'reference_url', 'response_mode',
        'edited_by_user_id', 'edited_at', 'edit_comment',
        'embedding_id',
    ];

    protected $casts = [
        'typical_cases_json' => 'array',
        'representative_rows_json' => 'array',
        'keywords_json' => 'array',
        'source_refs_json' => 'array',
        'confidence' => 'decimal:2',
        'edited_at' => 'datetime',
    ];

    /**
     * Check whether this KU can be edited.
     * Both approved and draft KUs are editable (simplified workflow).
     */
    public function isEditable(): bool
    {
        return true;
    }

    /** Whether this KU has a reference URL for link guidance. */
    public function hasReferenceUrl(): bool
    {
        return !empty($this->reference_url);
    }

    /** Whether this KU is in link-only response mode. */
    public function isLinkOnly(): bool
    {
        return $this->response_mode === 'link_only';
    }

    /** Whether this KU was manually registered (not from pipeline). */
    public function isManual(): bool
    {
        return $this->source_type === 'manual';
    }

    /** Scope: only pipeline-generated KUs. */
    public function scopePipeline($query)
    {
        return $query->where('source_type', 'pipeline');
    }

    /** Scope: only manually registered KUs. */
    public function scopeManual($query)
    {
        return $query->where('source_type', 'manual');
    }

    /** The cluster this KU was derived from (null for manual KUs). */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    /** The pipeline job that generated this KU. */
    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class);
    }

    /** The source dataset this KU traces back to. */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** Immutable version snapshots for audit trail. */
    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeUnitVersion::class);
    }

    /** Immutable review action records. */
    public function reviews(): HasMany
    {
        return $this->hasMany(KnowledgeUnitReview::class);
    }
}
