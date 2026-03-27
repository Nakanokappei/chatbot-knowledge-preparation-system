<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable pipeline configuration template.
 * Defines all parameters for embedding, clustering, LLM, and export.
 * Jobs snapshot this config at execution time for reproducibility.
 */
class PipelineConfig extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'name', 'version', 'config_json',
    ];

    protected $casts = [
        'config_json' => 'array',
    ];
}
