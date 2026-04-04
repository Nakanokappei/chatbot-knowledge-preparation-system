<?php

namespace App\Http\Controllers;

use App\Models\EmbedApiKey;
use App\Models\KnowledgePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage embed API keys for Knowledge Packages.
 *
 * Accessible by workspace owners from the package detail page.
 */
class EmbedApiKeyController extends Controller
{
    /**
     * List all API keys for a package.
     */
    public function index(KnowledgePackage $package): JsonResponse
    {
        $keys = EmbedApiKey::where('knowledge_package_id', $package->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($key) => [
                'id' => $key->id,
                'key_prefix' => $key->key_prefix,
                'allowed_domains' => $key->allowed_domains_json,
                'status' => $key->status,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'total_requests' => $key->total_requests,
                'created_at' => $key->created_at->toIso8601String(),
            ]);

        return response()->json(['keys' => $keys]);
    }

    /**
     * Generate a new API key for a package.
     *
     * Returns the plaintext key once — it cannot be retrieved again.
     */
    public function store(Request $request, KnowledgePackage $package): JsonResponse
    {
        $request->validate([
            'allowed_domains' => 'required|string|max:1000',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:1000',
        ]);

        // Parse comma-separated domain list
        $domains = array_map('trim', explode(',', $request->input('allowed_domains')));
        $domains = array_values(array_filter($domains));

        if (empty($domains)) {
            return response()->json(['error' => __('ui.embed_domains_required')], 422);
        }

        $result = EmbedApiKey::generate(
            auth()->user()->workspace_id,
            $package->id,
            $domains,
        );

        // Apply optional rate limit
        if ($request->filled('rate_limit_per_minute')) {
            $result['model']->update([
                'rate_limit_per_minute' => $request->input('rate_limit_per_minute'),
            ]);
        }

        return response()->json([
            'key' => $result['key'],  // Plaintext — shown once only
            'key_prefix' => $result['model']->key_prefix,
            'id' => $result['model']->id,
            'message' => __('ui.embed_key_created'),
        ]);
    }

    /**
     * Revoke an API key (soft delete — sets status to 'revoked').
     */
    public function revoke(EmbedApiKey $apiKey): JsonResponse
    {
        // Verify the key belongs to the authenticated user's workspace
        abort_if($apiKey->workspace_id !== auth()->user()->workspace_id, 403);

        $apiKey->update(['status' => 'revoked']);

        return response()->json([
            'message' => __('ui.embed_key_revoked'),
        ]);
    }
}
