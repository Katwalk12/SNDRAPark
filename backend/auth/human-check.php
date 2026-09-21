<?php

declare(strict_types=1);

/**
 * Hands the sign-up form a fresh verification question.
 *
 * Only the question travels; the answer stays in the session as a hash, so
 * reading this response tells a client nothing it can submit.
 *
 * GET so the form can ask for one on load, and again when the visitor wants a
 * different question.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/HumanCheck.php';
require_once __DIR__ . '/../utils/SessionManager.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
// A cached question would be answered once and replayed for every sign-up.
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    // Uses the same hardened cookie settings as the rest of the member side,
    // rather than opening a bare session of its own.
    SessionManager::prepareSessionStorage();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    $challenge = HumanCheck::issue();

    echo json_encode([
        'success' => true,
        'data' => [
            'question' => $challenge['question'],
            'expiresIn' => $challenge['expiresIn'],
            'fieldName' => HumanCheck::fieldName()
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('[human-check] ' . $exception->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not load a verification question.'
    ]);
}
