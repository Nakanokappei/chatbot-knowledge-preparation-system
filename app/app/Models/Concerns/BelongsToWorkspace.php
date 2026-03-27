<?php

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforce workspace isolation at the Eloquent level.
 *
 * Applies a global scope so that all queries on models using this trait
 * are automatically filtered by the authenticated user's workspace_id.
 * Also auto-fills workspace_id when creating new records.
 *
 * This implements Design Principle 5: Workspace Isolation.
 */
trait BelongsToWorkspace
{
    /**
     * Boot the trait by registering the global scope and auto-fill behavior.
     */
    protected static function bootBelongsToWorkspace(): void
    {
        // Automatically scope all queries to the current workspace
        static::addGlobalScope('workspace', function (Builder $query) {
            if (auth()->check() && auth()->user()->workspace_id) {
                $query->where($query->getModel()->getTable() . '.workspace_id', auth()->user()->workspace_id);
            }
        });

        // Automatically set workspace_id when creating a new record
        static::creating(function (Model $model) {
            if (auth()->check() && auth()->user()->workspace_id && empty($model->workspace_id)) {
                $model->workspace_id = auth()->user()->workspace_id;
            }
        });
    }

    /**
     * Define the relationship to the workspace.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
