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

    // A token minted for the anonymous visitor must not stay valid for the
    // signed-in teller -- the same rule admin_establish_session() follows.
    // The new one is returned below so the booth page is armed before its
    // first scan or payment.
    $csrfToken = CsrfMiddleware::refresh();

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
            'token' => session_id(),
            'csrfToken' => $csrfToken
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

    // A refusal this endpoint meant to make is not a server fault.
    //
    // RateLimiter::enforce() throws 429 to cap PIN guessing, and this block
    // was flattening it to 500 -- so a rate-limited teller saw "Failed to log
    // in to the parking booth", the console showed a server error, and the
    // limiter's own text ("Rate limit exceeded for action 'login'") was handed
    // to the client in the details bag. Carrying the status through gives the
    // teller the real reason and lets the page offer Retry-After.
    $status = (int) $exception->getCode();

    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    $safeMessages = [
        400 => 'Bad request.',
        401 => 'Incorrect PIN code.',
        403 => 'Forbidden.',
        405 => 'Method not allowed.',
        422 => 'The submitted data could not be processed.',
        429 => 'Too many sign-in attempts. Try again in a few minutes.'
    ];

    if ($status === 429 && !headers_sent()) {
        $retryAfter = RateLimiter::getResetTime('login', ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') . '|booth-pin');
        header('Retry-After: ' . max(1, $retryAfter));
    }

    booth_login_json_response([
        'success' => false,
        'message' => $safeMessages[$status] ?? 'Failed to log in to the parking booth.',
        // The driver's message is the schema on a query failure, so it only
        // travels when APP_DEBUG is on. It is in the log either way, above.
        'data' => booth_debug_details($exception)
    ], $status);
} finally {
    restore_error_handler();
}
