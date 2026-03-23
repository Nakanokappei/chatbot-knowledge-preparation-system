<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable pipeline configuration template.
 * Defines all parameters for embedding, clustering, LLM, and export.
 * Jobs snapshot this config at execution time for reproducibility.
 */
class PipelineConfig extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'version', 'config_json',
    ];

    protected $casts = [
        'config_json' => 'array',
    ];
}
