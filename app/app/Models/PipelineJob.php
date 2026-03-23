<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tracks the lifecycle of a pipeline execution.
 *
 * Status transitions follow the Job State Machine:
 * submitted -> validating -> preprocessing -> embedding -> clustering
 * -> cluster_analysis -> knowledge_unit_generation -> exporting -> completed
 *
 * pipeline_config_snapshot_json stores an immutable copy of the config
 * used for this run, ensuring reproducibility (Design Principle 1).
 */
class PipelineJob extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'dataset_id', 'pipeline_config_id', 'status',
        'progress', 'pipeline_config_snapshot_json', 'step_outputs_json',
        'error_detail', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'pipeline_config_snapshot_json' => 'array',
        'step_outputs_json' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Valid status transitions as defined in the Job State Machine.
     */
    public const STATUSES = [
        'submitted', 'validating', 'preprocessing', 'embedding',
        'clustering', 'cluster_analysis', 'knowledge_unit_generation',
        'exporting', 'completed', 'failed', 'cancelled',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function pipelineConfig(): BelongsTo
    {
        return $this->belongsTo(PipelineConfig::class);
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(Cluster::class);
    }

    public function knowledgeUnits(): HasMany
    {
        return $this->hasMany(KnowledgeUnit::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }
}
