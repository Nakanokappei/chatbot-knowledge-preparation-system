<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Intermediate artifact produced by the clustering step.
 * Groups related support log entries by embedding similarity.
 * LLM-generated fields are populated during cluster_analysis.
 */
class Cluster extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'pipeline_job_id', 'tenant_id', 'cluster_label', 'topic_name',
        'intent', 'summary', 'row_count', 'quality_score',
        'representative_row_ids_json',
    ];

    protected $casts = [
        'quality_score' => 'decimal:4',
        'representative_row_ids_json' => 'array',
    ];

    /** The pipeline job that produced this cluster. */
    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class);
    }

    /** Dataset rows assigned to this cluster via the membership pivot table. */
    public function datasetRows(): BelongsToMany
    {
        return $this->belongsToMany(DatasetRow::class, 'cluster_memberships')
            ->withPivot('membership_score')
            ->withTimestamps();
    }

    /** Knowledge units derived from this cluster by the LLM analysis step. */
    public function knowledgeUnits(): HasMany
    {
        return $this->hasMany(KnowledgeUnit::class);
    }
}
