<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a Knowledge Unit to a Knowledge Dataset.
 *
 * Stores the KU version at the time of inclusion for reproducibility.
 */
class KnowledgeDatasetItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'knowledge_dataset_id', 'knowledge_unit_id',
        'sort_order', 'included_version',
    ];

    /** The parent dataset this item belongs to. */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDataset::class, 'knowledge_dataset_id');
    }

    /** The knowledge unit referenced by this item. */
    public function knowledgeUnit(): BelongsTo
    {
        return $this->belongsTo(KnowledgeUnit::class);
    }
}
