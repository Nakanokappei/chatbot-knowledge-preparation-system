<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An embedding represents a specific column configuration applied to a
 * dataset, producing a vector space from which clusters and KUs are derived.
 *
 * This is the primary navigational entity in the sidebar — users browse
 * embeddings to view and manage their knowledge units.
 */
class Embedding extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'dataset_id', 'name', 'column_config_json',
        'embedding_model', 'status', 'row_count',
    ];

    protected $casts = [
        'column_config_json' => 'array',
    ];

    /** The source dataset this embedding was generated from. */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /** Knowledge units produced from this embedding's clusters. */
    public function knowledgeUnits(): HasMany
    {
        return $this->hasMany(KnowledgeUnit::class);
    }

    /** Clusters generated during the clustering pipeline step. */
    public function clusters(): HasMany
    {
        return $this->hasMany(Cluster::class);
    }

    /** Pipeline jobs associated with this embedding. */
    public function pipelineJobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class);
    }

    /**
     * Get the latest completed pipeline job for this embedding.
     */
    public function latestJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class, 'id', 'embedding_id')
            ->where('status', 'completed')
            ->latest();
    }

    /**
     * Human-readable status badge color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'ready' => '#34c759',
            'processing' => '#ff9500',
            'failed' => '#ff3b30',
            default => '#86868b',
        };
    }
}
