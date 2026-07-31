<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded source document (PDF) stored as-is in object storage.
 *
 * Documents are not processed yet: no text extraction, no embedding.
 * The record exists so the workspace can see, download, and delete what
 * has been uploaded.
 */
class Document extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'uploaded_by', 'original_filename', 'stored_path', 'byte_size',
    ];

    protected $casts = [
        'byte_size' => 'integer',
    ];

    /** The user who uploaded the file; null if that user was deleted. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Human-readable file size for display (e.g. "2.4 MB").
     */
    public function formattedSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->byte_size;
        $unitIndex = 0;

        // Step up units while the value stays above 1024
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        // Bytes are whole numbers; larger units keep one decimal
        return $unitIndex === 0
            ? sprintf('%d %s', $size, $units[$unitIndex])
            : sprintf('%.1f %s', $size, $units[$unitIndex]);
    }
}
