<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Top-level organizational boundary for multi-tenant isolation.
 */
class Tenant extends Model
{
    protected $fillable = ['name', 'status'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    public function pipelineJobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class);
    }
}
