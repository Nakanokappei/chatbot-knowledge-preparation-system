<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pre-computed vector for a KU within a published knowledge package.
 *
 * Vectors are generated using the package's embedding model at publish time,
 * ensuring all vectors in a package share the same vector space.
 */
class PackageVector extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'package_id', 'knowledge_unit_id',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(KnowledgePackage::class, 'package_id');
    }

    public function knowledgeUnit(): BelongsTo
    {
        return $this->belongsTo(KnowledgeUnit::class);
    }
}
