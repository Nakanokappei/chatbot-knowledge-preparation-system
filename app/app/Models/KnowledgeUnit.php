<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'dataset_id', 'pipeline_job_id', 'cluster_id',
        'topic', 'intent', 'summary', 'typical_cases_json',
        'cause_summary', 'resolution_summary', 'notes',
        'representative_rows_json', 'keywords_json', 'row_count',
        'confidence', 'review_status', 'source_refs_json',
        'pipeline_config_version', 'prompt_version', 'version',
    ];

    protected $casts = [
        'typical_cases_json' => 'array',
        'representative_rows_json' => 'array',
        'keywords_json' => 'array',
        'source_refs_json' => 'array',
        'confidence' => 'decimal:2',
    ];

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class);
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeUnitVersion::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(KnowledgeUnitReview::class);
    }
}
