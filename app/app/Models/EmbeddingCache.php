<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cache entry mapping content hash to embedding vector location in S3.
 * Cache key: hash(normalized_text + normalization_version + model_name + dimension)
 * Used to avoid redundant embedding API calls.
 */
class EmbeddingCache extends Model
{
    protected $table = 'embedding_cache';

    protected $fillable = [
        'embedding_hash', 'normalization_version',
        'model_name', 'dimension', 's3_path',
    ];
}
