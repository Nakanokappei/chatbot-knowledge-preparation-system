<?php

namespace App\Http\Controllers;

use App\Models\EmbedApiKey;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves the embedded chat page loaded inside an iframe.
 *
 * This controller is NOT behind Sanctum/session auth.
 * Authentication is via the API key in the URL token.
 */
class EmbedController extends Controller
{
    /**
     * Render the standalone chat page for iframe embedding.
     *
     * URL: GET /embed/chat/{token}?title=...&theme=...&color=...&greeting=...
     */
    public function show(Request $request, string $token): View
    {
        // Validate the API key
        $keyHash = hash('sha256', $token);
        $embedKey = EmbedApiKey::where('key_hash', $keyHash)->first();

        if (!$embedKey || !$embedKey->isValid()) {
            abort(403, 'Invalid or expired embed key.');
        }

        $embedKey->loadMissing('package');
        if (!$embedKey->package || !$embedKey->package->isPublished()) {
            abort(404, 'Package not available.');
        }

        // Customization via query parameters with validation
        $color = $request->query('color', '#0071e3');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) {
            $color = '#0071e3';
        }
        $theme = in_array($request->query('theme'), ['light', 'dark']) ? $request->query('theme') : 'light';

        $config = [
            'title' => $request->query('title', $embedKey->package->name),
            'theme' => $theme,
            'accent_color' => $color,
            'initial_message' => $request->query('greeting'),
            'api_key' => $token,
            'package_name' => $embedKey->package->name,
            'chat_endpoint' => url('/embed/api/chat'),
        ];

        return view('embed.chat', $config);
    }
}
