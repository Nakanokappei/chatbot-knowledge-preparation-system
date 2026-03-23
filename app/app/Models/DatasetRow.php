<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single support log entry from an uploaded dataset.
 * normalized_text and embedding_hash are populated during preprocessing.
 */
class DatasetRow extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'dataset_id', 'tenant_id', 'row_no', 'raw_text',
        'normalized_text', 'metadata_json', 'embedding_hash',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }
}
