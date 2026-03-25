<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Top-level organizational boundary for multi-tenant isolation.
 *
 * On creation, automatically provisions default LLM models so that
 * the pipeline can run without manual settings configuration.
 */
class Tenant extends Model
{
    protected $fillable = ['name', 'status', 'retrieve_rate_limit', 'chat_rate_limit', 'monthly_token_budget'];

    /**
     * Default LLM models provisioned for every new tenant.
     * Covers embedding (Titan) and analysis/chat (Claude Haiku).
     */
    private const DEFAULT_MODELS = [
        [
            'display_name' => 'Claude Haiku 4.5 (Default)',
            'model_id' => 'jp.anthropic.claude-haiku-4-5-20251001-v1:0',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 1,
            'input_price_per_1m' => 1.00,
            'output_price_per_1m' => 5.00,
        ],
        [
            'display_name' => 'Claude Sonnet 4.6',
            'model_id' => 'jp.anthropic.claude-sonnet-4-6',
            'is_default' => false,
            'is_active' => false,
            'sort_order' => 2,
            'input_price_per_1m' => 3.00,
            'output_price_per_1m' => 15.00,
        ],
    ];

    /**
     * Boot model event listeners to provision default resources.
     */
    protected static function booted(): void
    {
        // Provision default LLM models when a new tenant is created
        static::created(function (Tenant $tenant) {
            foreach (self::DEFAULT_MODELS as $model) {
                $tenant->llmModels()->create($model);
            }
        });
    }

    /** Users belonging to this tenant. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Datasets uploaded by this tenant. */
    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    /** Pipeline jobs executed under this tenant. */
    public function pipelineJobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class);
    }

    /** LLM models registered in this tenant's settings. */
    public function llmModels(): HasMany
    {
        return $this->hasMany(LlmModel::class);
    }
}
