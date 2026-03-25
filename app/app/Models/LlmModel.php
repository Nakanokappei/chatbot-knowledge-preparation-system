<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents an LLM model available for pipeline cluster analysis.
 *
 * Users manage models via the settings UI. Each tenant maintains
 * its own registry of available models with one marked as default.
 */
class LlmModel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'display_name',
        'model_id',
        'is_default',
        'sort_order',
        'is_active',
        'input_price_per_1m',
        'output_price_per_1m',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Scope to only active models, ordered by sort_order.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
