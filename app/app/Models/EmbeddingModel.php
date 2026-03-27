<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Embedding model registry entry for a workspace.
 *
 * Tracks which Bedrock embedding models are available for use
 * in the pipeline's embedding step.
 */
class EmbeddingModel extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'display_name', 'model_id', 'dimension',
        'is_default', 'is_active', 'sort_order', 'input_price_per_1m',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
