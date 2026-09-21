<?php

declare(strict_types=1);

/**
 * Hand the current session's CSRF token to same-origin JavaScript.
 *
 * The admin dashboard never needed this: every admin response carries a fresh
 * csrfToken in its payload, so the client always has one by the time it posts.
 * The member and booth pages have no such guarantee -- a page can load and go
 * straight to a POST without a prior successful response to read a token from
 * -- so assets/js/csrf.js fetches one here before its first state-changing
 * request.
 *
 * Unauthenticated on purpose. The token is bound to the session cookie and is
 * worthless without it, and CORS is what stops a hostile origin reading the
 * body: CorsHelper only names origins on the allow list, so a cross-origin
 * fetch of this endpoint is blocked before the response is readable. Requiring
 * a login here would instead break the one case the endpoint exists for --
 * arming the sign-up and reset pages, which run before there is a session.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/CorsHelper.php';
require_once __DIR__ . '/../utils/CsrfGuard.php';
require_once __DIR__ . '/../utils/SessionManager.php';

header('Content-Type: application/json');

// Same storage path as every other entry point. Without this the token could
// be written to a session file the endpoints that validate it cannot read.
SessionManager::prepareSessionStorage();

CorsHelper::sendHeaders('GET', true, 'Content-Type, X-CSRF-Token');

// A cached token is a token that has been rotated out from under the client.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'CSRF token issued.',
    'data' => [
        'csrfToken' => CsrfGuard::issueToken()
    ]
]);
