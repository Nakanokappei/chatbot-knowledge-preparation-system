<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Embed API key for widget/iframe authentication.
 *
 * Each key is scoped to a single Knowledge Package. The plaintext key
 * is shown once at creation; only the SHA-256 hash is stored in DB.
 */
class EmbedApiKey extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'knowledge_package_id',
        'key_hash', 'key_prefix', 'allowed_domains_json',
        'status', 'expires_at', 'rate_limit_per_minute',
    ];

    protected $casts = [
        'allowed_domains_json' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a new API key and return plaintext + model.
     *
     * The plaintext key is prefixed with 'kps_' for easy identification
     * and is 52 characters total (4 prefix + 48 hex).
     */
    public static function generate(int $workspaceId, int $packageId, array $domains): array
    {
        $plainKey = 'kps_' . bin2hex(random_bytes(24));

        $model = static::create([
            'workspace_id' => $workspaceId,
            'knowledge_package_id' => $packageId,
            'key_hash' => hash('sha256', $plainKey),
            'key_prefix' => substr($plainKey, 0, 8),
            'allowed_domains_json' => $domains,
        ]);

        return ['key' => $plainKey, 'model' => $model];
    }

    /** Check if this key is active and not expired. */
    public function isValid(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The knowledge package this key grants access to. */
    public function package(): BelongsTo
    {
        return $this->belongsTo(KnowledgePackage::class, 'knowledge_package_id');
    }
}
