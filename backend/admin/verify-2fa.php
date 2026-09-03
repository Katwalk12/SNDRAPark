<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

/**
 * Second step of admin sign-in.
 *
 * login.php verified the password and emailed a code, keeping only its hash in
 * the session. This checks the code and, on success, creates the session
 * through the same path a single-factor login uses.
 */

admin_require_method('POST');

try {
    $connection = admin_db();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $pending = $_SESSION['admin_pending_2fa'] ?? null;

    if (!is_array($pending)) {
        admin_error('Start again from the sign-in form.', 440);
    }

    if (time() > (int) ($pending['expires_at'] ?? 0)) {
        unset($_SESSION['admin_pending_2fa']);
        admin_error('That code has expired. Sign in again to get a new one.', 440);
    }

    // Five guesses is generous for a code the right person is reading off a
    // screen, and far too few to search a six-digit space.
    if ((int) ($pending['attempts'] ?? 0) >= 5) {
        unset($_SESSION['admin_pending_2fa']);
        admin_error('Too many incorrect codes. Sign in again to get a new one.', 429);
    }

    $code = preg_replace('/\D+/', '', (string) admin_input('code'));

    if ($code === '' || strlen($code) !== 6) {
        $_SESSION['admin_pending_2fa']['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        admin_error('Enter the 6-digit code from your email.', 422);
    }

    if (!password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
        $_SESSION['admin_pending_2fa']['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;

        admin_audit_log($connection, null, 'ADMIN_2FA_FAILED', 'An incorrect admin sign-in code was submitted.', [
            'target_type' => 'auth',
            'status' => 'failure',
            'admin_email' => (string) ($pending['email'] ?? ''),
            'metadata' => ['attempts' => (int) $_SESSION['admin_pending_2fa']['attempts']]
        ]);

        admin_error('That code is not correct.', 401);
    }

    $staffId = (int) ($pending['staff_id'] ?? 0);
    $statement = $connection->prepare("
        SELECT id, full_name, username, email, role, is_active
        FROM staff_accounts
        WHERE id = ?
        LIMIT 1
    ");
    $statement->bind_param('i', $staffId);
    $statement->execute();
    $staff = $statement->get_result()->fetch_assoc();

    // The account could have been disabled in the five minutes since the
    // password check, so it is re-read rather than trusted from the session.
    if (!$staff || (int) ($staff['is_active'] ?? 0) !== 1
        || !in_array((string) ($staff['role'] ?? ''), ['admin', 'supervisor'], true)) {
        unset($_SESSION['admin_pending_2fa']);
        admin_error('That account can no longer sign in.', 403);
    }

    admin_json_response(admin_establish_session($connection, $staff));
} catch (Throwable $exception) {
    admin_log('admin-verify-2fa-failed', ['error' => $exception->getMessage()]);
    admin_error('Failed to verify the sign-in code.', 500);
}
