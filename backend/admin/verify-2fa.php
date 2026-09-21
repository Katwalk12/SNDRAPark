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

    // The dialog's X (and Escape, and a click on the backdrop) posts this.
    // Closing the dialog client-side would leave the challenge sitting in the
    // session for its full five minutes, so the code that was already emailed
    // is dropped here instead -- abandoning the sign-in actually abandons it.
    //
    // Idempotent on purpose: cancelling when there is nothing pending is a
    // success, not an error, because that is what a double click looks like.
    if (strtolower(admin_clean_text(admin_input('action'))) === 'cancel') {
        $pendingEmail = (string) ($_SESSION['admin_pending_2fa']['email'] ?? '');
        unset($_SESSION['admin_pending_2fa']);

        if ($pendingEmail !== '') {
            admin_audit_log($connection, null, 'ADMIN_2FA_CANCELLED', 'An admin sign-in was cancelled at the code step.', [
                'target_type' => 'auth',
                'status' => 'success',
                'admin_email' => $pendingEmail
            ]);
        }

        admin_success('Sign-in cancelled.');
    }

    $pending = $_SESSION['admin_pending_2fa'] ?? null;

    // 401, not 440. Apache has no reason phrase for 440 and rewrites it to a
    // 500, so a challenge that had simply expired -- or been cancelled a
    // moment earlier -- was reported to the browser as a server fault. This is
    // the same trap admin_validate_csrf() documents for 419.
    if (!is_array($pending)) {
        admin_error('Start again from the sign-in form.', 401);
    }

    if (time() > (int) ($pending['expires_at'] ?? 0)) {
        unset($_SESSION['admin_pending_2fa']);
        admin_error('That code has expired. Sign in again to get a new one.', 401);
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
