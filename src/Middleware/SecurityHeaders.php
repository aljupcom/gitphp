<?php

declare(strict_types=1);

namespace App\Middleware;

final class SecurityHeaders
{
    /** Send standard security headers with the HTTP response. */
    public function apply(): void
    {
        if (headers_sent()) return;
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
            . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com; "
            // https: keeps README images & badges (e.g. img.shields.io) rendering.
            . "img-src 'self' data: https:; "
            . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
        );

        // HSTS only makes sense (and is only honored) over HTTPS.
        if (($_SERVER['HTTPS'] ?? '') === 'on' || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
