<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Set the application locale from session or browser preference.
 *
 * Priority:
 * 1. Session (user explicitly chose a language via EN/JA buttons)
 * 2. Browser Accept-Language header (automatic detection)
 * 3. App default (config/app.php locale)
 */
class SetLocale
{
    private const SUPPORTED = ['en', 'ja'];

    /**
     * Resolve the locale from session or browser headers and apply it.
     */
    public function handle(Request $request, Closure $next)
    {
        $locale = session('locale');

        // If no session preference, detect from browser Accept-Language
        if (!$locale) {
            $locale = $this->detectFromBrowser($request);
        }

        if ($locale && in_array($locale, self::SUPPORTED)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }

    /**
     * Parse the Accept-Language header and return the best matching locale.
     *
     * Example header: "ja,en-US;q=0.9,en;q=0.8"
     * This would return "ja" since it has the highest priority.
     */
    private function detectFromBrowser(Request $request): ?string
    {
        $header = $request->header('Accept-Language', '');
        if (!$header) {
            return null;
        }

        // Parse "ja,en-US;q=0.9,en;q=0.8" into sorted list
        $languages = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_contains($part, ';q=')) {
                [$lang, $q] = explode(';q=', $part, 2);
                $quality = (float) $q;
            } else {
                $lang = $part;
                $quality = 1.0;
            }
            // Normalize: "en-US" → "en"
            $lang = strtolower(explode('-', trim($lang))[0]);
            $languages[$lang] = max($languages[$lang] ?? 0, $quality);
        }

        // Sort by quality descending
        arsort($languages);

        // Return first supported match
        foreach ($languages as $lang => $quality) {
            if (in_array($lang, self::SUPPORTED)) {
                return $lang;
            }
        }

        return null;
    }
}
