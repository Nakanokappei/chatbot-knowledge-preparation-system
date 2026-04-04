<?php

namespace App\Http\Middleware;

use App\Models\EmbedApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate embed API requests using a per-package API key.
 *
 * Completely separate from Sanctum / session authentication.
 * Checks: key validity, package publication status, and domain restriction.
 */
class EmbedApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Extract API key from Authorization header or query param
        $apiKey = $request->bearerToken()
            ?? $request->header('X-Embed-Key')
            ?? $request->query('key');

        if (!$apiKey) {
            return response()->json(['error' => 'API key required.'], 401);
        }

        // Look up key by hash
        $keyHash = hash('sha256', $apiKey);
        $embedKey = EmbedApiKey::where('key_hash', $keyHash)->first();

        if (!$embedKey || !$embedKey->isValid()) {
            return response()->json(['error' => 'Invalid or expired API key.'], 401);
        }

        // Verify the linked package is published
        $embedKey->loadMissing('package');
        if (!$embedKey->package || !$embedKey->package->isPublished()) {
            return response()->json(['error' => 'Package not available.'], 404);
        }

        // Domain restriction check (for browser-originated requests)
        $origin = $request->header('Origin') ?? $request->header('Referer');
        if ($origin && !$this->isDomainAllowed($origin, $embedKey->allowed_domains_json ?? [])) {
            return response()->json(['error' => 'Domain not allowed.'], 403);
        }

        // Update usage stats atomically (no read-modify-write race)
        EmbedApiKey::where('id', $embedKey->id)->update([
            'last_used_at' => now(),
            'total_requests' => DB::raw('total_requests + 1'),
        ]);

        // Bind context for downstream controllers
        $request->attributes->set('embed_api_key', $embedKey);
        $request->attributes->set('embed_workspace_id', $embedKey->workspace_id);
        $request->attributes->set('embed_package_id', $embedKey->knowledge_package_id);
        $request->attributes->set('embed_package', $embedKey->package);

        // Set CORS headers based on allowed domains
        $response = $next($request);
        if ($origin && $this->isDomainAllowed($origin, $embedKey->allowed_domains_json ?? [])) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Embed-Key');
            $response->headers->set('Access-Control-Max-Age', '3600');
        }

        return $response;
    }

    /**
     * Check if the request origin matches the allowed domains list.
     *
     * Supports exact match and wildcard subdomains (*.example.com).
     */
    private function isDomainAllowed(string $origin, array $allowedDomains): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        foreach ($allowedDomains as $domain) {
            // Exact match
            if ($domain === $host) {
                return true;
            }
            // Wildcard: *.example.com matches sub.example.com and example.com
            // Dot-prefix ensures evilexample.com does NOT match *.example.com
            if (str_starts_with($domain, '*.')) {
                $base = substr($domain, 2); // ".example.com"
                $apex = ltrim($base, '.');  // "example.com"
                if ($host === $apex || str_ends_with($host, '.' . $apex)) {
                    return true;
                }
            }
        }

        return false;
    }
}
