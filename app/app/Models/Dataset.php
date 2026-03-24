<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents an uploaded support log file (CSV/TSV).
 * Contains many dataset_rows, which are the individual log entries.
 */
class Dataset extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'source_type', 'original_filename',
        's3_raw_path', 'row_count', 'schema_json',
    ];

    protected $casts = [
        'schema_json' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(DatasetRow::class);
    }

    public function pipelineJobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class);
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(Embedding::class);
    }
}
