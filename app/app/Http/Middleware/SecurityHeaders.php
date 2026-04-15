<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emit hardening HTTP response headers on every request.
 *
 * Defense-in-depth against common browser-side attacks:
 *   - HSTS            : forbid protocol downgrade (HTTPS only)
 *   - X-Frame-Options : clickjacking protection
 *   - X-Content-Type-Options : MIME sniffing protection
 *   - Referrer-Policy : limit leaked referrers to cross-origin sites
 *   - Permissions-Policy : disable unused powerful APIs by default
 *   - Content-Security-Policy : mitigate reflected/stored XSS
 *
 * Embed iframe routes need relaxed framing — they are served inside
 * customer sites — so /embed/* skips X-Frame-Options and uses a
 * permissive frame-ancestors in CSP instead.
 */
class SecurityHeaders
{
    /**
     * Add security headers to the outgoing response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Classify the request so embed iframes can be hosted cross-origin
        // while keeping the admin UI non-framable.
        $isEmbedRoute = $request->is('embed/*');

        // -----------------------------------------------------------------
        // Framing protection
        // -----------------------------------------------------------------
        // Only attach X-Frame-Options to the admin/app surface. Leaving it
        // off for embed keeps the legitimate iframe use case working.
        if (!$isEmbedRoute) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        // -----------------------------------------------------------------
        // MIME sniffing / referrer policy / permissions policy
        // -----------------------------------------------------------------
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()'
        );

        // -----------------------------------------------------------------
        // HSTS — only meaningful when the client already connected over HTTPS
        // -----------------------------------------------------------------
        // We gate on the effective scheme rather than APP_ENV because the
        // dev environment also serves HTTPS through the ALB. Two years of
        // max-age + preload readiness is deferred to a later hardening step;
        // one year + includeSubDomains is the first-pass value.
        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // -----------------------------------------------------------------
        // Content-Security-Policy
        // -----------------------------------------------------------------
        // Vite dev server uses inline styles; production bundles ship with
        // hashed assets. We allow 'unsafe-inline' on style-src for now to
        // avoid breaking the UI — a follow-up task should switch to nonces
        // or SRI hashes. JavaScript is tightly scoped to self + CDN.
        //
        // Embed pages additionally need frame-ancestors=<customer domains>.
        // The EmbedApiKeyAuth middleware already enforces per-key origin
        // allowlists, so here we set a permissive frame-ancestors so the
        // iframe can load under any approved site.
        $scriptSrc = "'self'";
        $styleSrc  = "'self' 'unsafe-inline'";
        $imgSrc    = "'self' data: blob: https:";
        $fontSrc   = "'self' data:";
        $connectSrc = "'self'";
        $frameAncestors = $isEmbedRoute ? '*' : "'none'";

        $csp = sprintf(
            "default-src 'self'; script-src %s; style-src %s; img-src %s; font-src %s; connect-src %s; frame-ancestors %s; base-uri 'self'; form-action 'self'; object-src 'none'",
            $scriptSrc,
            $styleSrc,
            $imgSrc,
            $fontSrc,
            $connectSrc,
            $frameAncestors
        );

        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
