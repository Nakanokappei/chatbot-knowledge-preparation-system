<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a Knowledge Unit to a Knowledge Package.
 *
 * Stores the KU version at the time of inclusion for reproducibility.
 */
class KnowledgePackageItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'knowledge_package_id', 'knowledge_unit_id',
        'sort_order', 'included_version',
    ];

    /** The parent package this item belongs to. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(KnowledgePackage::class, 'knowledge_package_id');
    }

    /** The knowledge unit referenced by this item. */
    public function knowledgeUnit(): BelongsTo
    {
        return $this->belongsTo(KnowledgeUnit::class);
    }
}
