<?php

declare(strict_types=1);

ob_start();
header('Content-Type: application/json');

function booth_login_json_response(array $payload, int $status = 200): void
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
    require_once __DIR__ . '/../admin/common.php';

    admin_require_method('POST');

    $connection = admin_db();
    $pin = preg_replace('/\D+/', '', admin_clean_text(admin_input('pin')));

    if ($pin === '') {
        booth_login_json_response([
            'success' => false,
            'message' => 'PIN code is required.'
        ], 422);
    }

    if (strlen($pin) !== BOOTH_PIN_LENGTH) {
        booth_login_json_response([
            'success' => false,
            'message' => 'PIN code must be exactly ' . BOOTH_PIN_LENGTH . ' digits.'
        ], 422);
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    RateLimiter::enforce('login', $ipAddress, 'booth-pin');

    $attemptKey = 'booth_pin_failed_attempts';
    $lockKey = 'booth_pin_locked_until';
    $now = time();

    if (!empty($_SESSION[$lockKey]) && (int) $_SESSION[$lockKey] > $now) {
        $seconds = max(1, (int) $_SESSION[$lockKey] - $now);
        booth_login_json_response([
            'success' => false,
            'message' => 'Too many incorrect PIN attempts. Try again in ' . ceil($seconds / 60) . ' minute(s).'
        ], 429);
    }

    $statement = $connection->prepare("
        SELECT id, teller_name, teller_details, pin_code, is_active
        FROM booth_teller_accounts
        WHERE is_active = 1
        ORDER BY created_at DESC
    ");
    $statement->execute();
    $result = $statement->get_result();
    $teller = null;

    while ($row = $result->fetch_assoc()) {
        if (password_verify($pin, (string) ($row['pin_code'] ?? ''))) {
            $teller = $row;
            break;
        }
    }

    if (!$teller) {
        $_SESSION[$attemptKey] = ((int) ($_SESSION[$attemptKey] ?? 0)) + 1;
        if ((int) $_SESSION[$attemptKey] >= 5) {
            $_SESSION[$lockKey] = $now + 300;
            $_SESSION[$attemptKey] = 0;
        }
        booth_login_json_response([
            'success' => false,
            'message' => 'Incorrect PIN code.'
        ], 401);
    }

    $_SESSION[$attemptKey] = 0;
    unset($_SESSION[$lockKey]);

    session_regenerate_id(true);

    $tellerId = (int) $teller['id'];
    $tellerName = (string) ($teller['teller_name'] ?? 'Booth Teller');
    $tellerDetails = (string) ($teller['teller_details'] ?? '');

    $_SESSION['sndra_admin'] = [
        'id' => $tellerId,
        'role' => 'booth',
        'accountType' => 'booth_teller_pin',
        'fullName' => $tellerName,
        'email' => '',
        'details' => $tellerDetails
    ];
    $_SESSION['_admin_last_activity'] = time();

    $updateStatement = $connection->prepare("UPDATE booth_teller_accounts SET last_login_at = NOW() WHERE id = ?");
    $updateStatement->bind_param('i', $tellerId);
    $updateStatement->execute();

    booth_login_json_response([
        'success' => true,
        'message' => 'Login successful.',
        'redirect' => 'parking-booth.html',
        'role' => 'booth',
        'data' => [
            'id' => $tellerId,
            'role' => 'booth',
            'fullName' => $tellerName,
            'details' => $tellerDetails,
            'email' => '',
            'token' => session_id()
        ]
    ]);
} catch (Throwable $exception) {
    if (function_exists('admin_log')) {
        admin_log('booth-login-failed', [
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    } else {
        error_log('[booth-login] ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    }

    booth_login_json_response([
        'success' => false,
        'message' => 'Failed to log in to the parking booth.',
        'data' => [
            'details' => $exception->getMessage()
        ]
    ], 500);
} finally {
    restore_error_handler();
}
