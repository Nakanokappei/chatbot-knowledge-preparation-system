<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforce tenant isolation at the Eloquent level.
 *
 * Applies a global scope so that all queries on models using this trait
 * are automatically filtered by the authenticated user's tenant_id.
 * Also auto-fills tenant_id when creating new records.
 *
 * This implements Design Principle 5: Tenant Isolation.
 */
trait BelongsToTenant
{
    /**
     * Boot the trait by registering the global scope and auto-fill behavior.
     */
    protected static function bootBelongsToTenant(): void
    {
        // Automatically scope all queries to the current tenant
        static::addGlobalScope('tenant', function (Builder $query) {
            if (auth()->check() && auth()->user()->tenant_id) {
                $query->where($query->getModel()->getTable() . '.tenant_id', auth()->user()->tenant_id);
            }
        });

        // Automatically set tenant_id when creating a new record
        static::creating(function (Model $model) {
            if (auth()->check() && auth()->user()->tenant_id && empty($model->tenant_id)) {
                $model->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    /**
     * Define the relationship to the tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
