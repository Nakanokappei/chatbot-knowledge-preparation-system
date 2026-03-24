<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Embedding model registry entry for a tenant.
 *
 * Tracks which Bedrock embedding models are available for use
 * in the pipeline's embedding step.
 */
class EmbeddingModel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'display_name', 'model_id', 'dimension',
        'is_default', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
