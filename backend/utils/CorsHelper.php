<?php

declare(strict_types=1);

require_once __DIR__ . '/EnvHelper.php';

/**
 * One place that decides which origin may talk to the API.
 *
 * The endpoints used to answer `Access-Control-Allow-Origin: *` while also
 * sending `Access-Control-Allow-Credentials: true`. Browsers reject that pair
 * outright, so the wildcard bought nothing and any genuine cross-origin client
 * failed. These helpers echo back the caller's origin only when it is on the
 * allow list, which is what a credentialed request actually needs.
 */
class CorsHelper
{
    /** Origins that may call the API, derived from APP_URL plus the usual local hosts. */
    public static function allowedOrigins(): array
    {
        $origins = [];
        $appUrl = (string) EnvHelper::get('APP_URL', '');

        if ($appUrl !== '') {
            $parts = parse_url($appUrl);

            if (!empty($parts['host'])) {
                $scheme = $parts['scheme'] ?? 'http';
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $origins[] = strtolower($scheme . '://' . $parts['host'] . $port);
            }
        }

        // The app is served from XAMPP on the same machine during development,
        // where the host may be typed either way.
        foreach (['http://localhost', 'http://127.0.0.1'] as $localOrigin) {
            $origins[] = $localOrigin;
        }

        $extra = (string) EnvHelper::get('CORS_EXTRA_ORIGINS', '');

        foreach (array_filter(array_map('trim', explode(',', $extra))) as $candidate) {
            $origins[] = strtolower(rtrim($candidate, '/'));
        }

        return array_values(array_unique($origins));
    }

    /** The Origin header if it is allowed, otherwise the configured app origin. */
    public static function resolveOrigin(): string
    {
        $allowed = self::allowedOrigins();
        $requestOrigin = strtolower(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')));

        if ($requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return $allowed[0] ?? 'http://localhost';
    }

    /**
     * Send the CORS headers for an endpoint.
     *
     * Credentialed endpoints (anything reading a session cookie) must name a
     * single origin, so Vary: Origin keeps caches from crossing the wires.
     */
    public static function sendHeaders(string $allowedMethods, bool $withCredentials = true, string $allowedHeaders = 'Content-Type, X-CSRF-Token'): void
    {
        header('Access-Control-Allow-Origin: ' . self::resolveOrigin());
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: ' . $allowedMethods . ', OPTIONS');
        header('Access-Control-Allow-Headers: ' . $allowedHeaders);

        if ($withCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
    }
}
