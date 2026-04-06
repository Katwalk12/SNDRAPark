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

    if (!$staff || (string) ($staff['role'] ?? '') !== 'admin' || (int) ($staff['is_active'] ?? 0) !== 1) {
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

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);
    $csrfToken = CsrfMiddleware::refresh();

    $staffId = (int) $staff['id'];
    $fullName = (string) ($staff['full_name'] ?? 'Administrator');
    $staffEmail = (string) ($staff['email'] ?? $email);

    $_SESSION['sndra_admin'] = [
        'id' => $staffId,
        'role' => 'admin',
        'fullName' => $fullName,
        'email' => $staffEmail
    ];
    $_SESSION['_admin_last_activity'] = time();

    $updateStatement = $connection->prepare("UPDATE staff_accounts SET last_login_at = NOW() WHERE id = ?");
    $updateStatement->bind_param('i', $staffId);
    $updateStatement->execute();

    admin_audit_log($connection, [
        'id' => $staffId,
        'fullName' => $fullName,
        'email' => $staffEmail
    ], 'ADMIN_LOGIN_SUCCESS', 'Admin logged in successfully.', [
        'target_type' => 'auth',
        'status' => 'success'
    ]);

    admin_login_json_response([
        'success' => true,
        'message' => 'Login successful.',
        'redirect' => 'admin-dashboard.html',
        'role' => 'admin',
        'data' => [
            'id' => $staffId,
            'role' => 'admin',
            'fullName' => $fullName,
            'email' => $staffEmail,
            'token' => session_id(),
            'csrfToken' => $csrfToken
        ]
    ]);
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
