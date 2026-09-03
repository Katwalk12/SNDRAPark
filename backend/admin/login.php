<?php

declare(strict_types=1);

ob_start();
header('Content-Type: application/json');

function admin_login_json_response(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/common.php';

    admin_require_method('POST');

    $connection = admin_db();
    $email = strtolower(admin_clean_text(admin_input('email')));
    $password = admin_clean_text(admin_input('password'));

    if ($email === '' || $password === '') {
        admin_audit_log($connection, null, 'ADMIN_LOGIN_FAILED', 'Admin login failed because email or password was missing.', [
            'admin_email' => $email,
            'status' => 'failure',
            'target_type' => 'auth',
            'metadata' => [
                'reason' => 'missing_credentials'
            ]
        ]);

        admin_login_json_response([
            'success' => false,
            'message' => 'Email and password are required.'
        ], 422);
    }

    $statement = $connection->prepare("
        SELECT id, full_name, username, email, password_hash, role, is_active
        FROM staff_accounts
        WHERE email = ?
        LIMIT 1
    ");
    $statement->bind_param('s', $email);
    $statement->execute();
    $staff = $statement->get_result()->fetch_assoc();

    // Supervisors sign in here too; admin_require_auth() is what holds them to
    // read-only once they are in.
    $staffRole = (string) ($staff['role'] ?? '');

    if (!$staff || !in_array($staffRole, ['admin', 'supervisor'], true) || (int) ($staff['is_active'] ?? 0) !== 1) {
        admin_audit_log($connection, null, 'ADMIN_LOGIN_FAILED', 'Admin login failed due to invalid account credentials.', [
            'admin_email' => $email,
            'status' => 'failure',
            'target_type' => 'auth',
            'metadata' => [
                'reason' => 'invalid_account'
            ]
        ]);

        admin_login_json_response([
            'success' => false,
            'message' => 'Invalid admin account credentials.'
        ], 401);
    }

    if (!admin_staff_password_verify($password, (string) $staff['password_hash'])) {
        admin_audit_log($connection, [
            'id' => (int) ($staff['id'] ?? 0),
            'fullName' => (string) ($staff['full_name'] ?? ''),
            'email' => (string) ($staff['email'] ?? $email)
        ], 'ADMIN_LOGIN_FAILED', 'Admin login failed due to invalid password.', [
            'status' => 'failure',
            'target_type' => 'auth',
            'metadata' => [
                'reason' => 'invalid_password'
            ]
        ]);

        admin_login_json_response([
            'success' => false,
            'message' => 'Invalid admin account credentials.'
        ], 401);
    }

    // One password stands between anyone and every user record, rate and
    // payment in the system. When the second factor is switched on, the
    // session is not created until the emailed code comes back.
    if ((int) system_settings_value('admin_2fa_enabled', $connection) === 1) {
        if (admin_start_two_factor_challenge($staff)) {
            admin_audit_log($connection, [
                'id' => (int) $staff['id'],
                'fullName' => (string) ($staff['full_name'] ?? ''),
                'email' => (string) ($staff['email'] ?? $email)
            ], 'ADMIN_2FA_CHALLENGE_SENT', 'A sign-in code was emailed for admin two-factor authentication.', [
                'target_type' => 'auth',
                'status' => 'success'
            ]);

            admin_login_json_response([
                'success' => true,
                'requiresTwoFactor' => true,
                'message' => 'We emailed a 6-digit code to ' . admin_mask_email((string) ($staff['email'] ?? $email))
                    . '. Enter it to finish signing in.'
            ]);
        }

        // The code could not be sent. Locking the only administrator out
        // because SMTP is unavailable would be worse than the risk it covers,
        // so the login proceeds and the failure is recorded.
        admin_log('admin-2fa-mail-failed', ['email' => (string) ($staff['email'] ?? $email)]);
    }

    admin_login_json_response(admin_establish_session($connection, $staff, $email));
} catch (Throwable $exception) {
    if (function_exists('admin_log')) {
        admin_log('admin-login-failed', [
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    } else {
        error_log('[admin-login] ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    }

    admin_login_json_response([
        'success' => false,
        'message' => 'Failed to log in to the admin dashboard.',
        'data' => [
            'details' => $exception->getMessage()
        ]
    ], 500);
} finally {
    restore_error_handler();
}
