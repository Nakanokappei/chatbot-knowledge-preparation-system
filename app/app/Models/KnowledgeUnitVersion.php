<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a Knowledge Unit at a specific version.
 * Provides audit trail for all edits and re-generations.
 */
class KnowledgeUnitVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'knowledge_unit_id', 'version', 'snapshot_json', 'created_at',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    /** The knowledge unit this version snapshot belongs to. */
    public function knowledgeUnit(): BelongsTo
    {
        return $this->belongsTo(KnowledgeUnit::class);
    }
}
