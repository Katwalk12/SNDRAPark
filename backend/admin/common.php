<?php

declare(strict_types=1);

// Sets the Asia/Manila timezone. Without it these endpoints ran on the
// php.ini default while MySQL ran on system time, and every timestamp
// they wrote or compared was hours out.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/system-settings.php';
require_once __DIR__ . '/../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../middleware/RBACMiddleware.php';
require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/audit-log.php';

/**
 * Booth teller PINs are a fixed length, checked both where they are issued
 * (manage_booth_staff.php) and where they are used (parking-booth/login.php).
 *
 * The keypad in frontend/js/booth-login.js mirrors this as BOOTH_PIN_LENGTH
 * and must be changed with it.
 */
if (!defined('BOOTH_PIN_LENGTH')) {
    define('BOOTH_PIN_LENGTH', 4);
}

/**
 * Idle timeout for an admin session, in seconds. Applied to the session GC
 * lifetime in admin_harden_session_cookie() and enforced per request in
 * admin_require_auth().
 */
if (!defined('ADMIN_SESSION_TIMEOUT')) {
    define('ADMIN_SESSION_TIMEOUT', 1800);
}

if (!function_exists('admin_prepare_session_storage')) {
    function admin_prepare_session_storage(): void
    {
        $configuredPath = session_save_path();
        $activePath = $configuredPath !== '' ? $configuredPath : sys_get_temp_dir();
        $hasWritablePath = $activePath !== '' && is_dir($activePath) && is_writable($activePath);

        if ($hasWritablePath) {
            return;
        }

        $fallbackPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';

        if (!is_dir($fallbackPath)) {
            mkdir($fallbackPath, 0777, true);
        }

        if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
            session_save_path($fallbackPath);
        }
    }
}

if (!function_exists('admin_harden_session_cookie')) {
    /**
     * The admin cookie carries the whole dashboard, so it is worth more than a
     * member's. These flags have to be set before session_start() -- once the
     * session is open PHP has already emitted Set-Cookie and ini_set is
     * ignored, which is why this runs above the start call rather than beside
     * the timeout check in admin_require_auth().
     *
     * Secure is conditional: forcing it on plain-HTTP XAMPP would make the
     * browser drop the cookie and nobody could sign in at all.
     */
    function admin_harden_session_cookie(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $isHttps ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_lifetime', '0');

        // admin_require_auth() reads this back as the idle timeout, so the
        // 30 minutes documented there is set here rather than left to php.ini
        // (whose 1440s default silently made it 24).
        ini_set('session.gc_maxlifetime', (string) ADMIN_SESSION_TIMEOUT);
    }
}

if (!function_exists('admin_send_security_headers')) {
    /**
     * Mirrors booth_send_common_headers(). Admin responses are JSON holding
     * member records and payment totals: nosniff stops a browser rendering one
     * as HTML, DENY keeps the dashboard out of a frame, and no-store keeps it
     * out of the disk cache on a shared machine.
     */
    function admin_send_security_headers(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
}

if (!function_exists('admin_register_exception_handler')) {
    /**
     * Backstop for anything thrown outside an endpoint's own try block.
     *
     * The admin endpoints call admin_require_auth() at the top level, above
     * their try, and that path can throw -- the rate limiter raises a 429.
     * With no handler PHP turned that into a fatal, so the dashboard received
     * an HTML stack trace (absolute paths included) where it expected JSON.
     * parking-booth and api/v1 already install ErrorMiddleware; this does the
     * same job while keeping the {success, message, data} shape the admin
     * dashboard parses.
     */
    function admin_register_exception_handler(): void
    {
        set_exception_handler(static function (Throwable $exception): void {
            $status = (int) $exception->getCode();

            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            admin_log('admin-unhandled-exception', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'status' => $status
            ]);

            // Unlike an endpoint's own catch block, this handler has no idea
            // where the exception came from, so the message is never reused --
            // a driver error that happens to carry a 4xx code would otherwise
            // be repeated verbatim to the client. The status picks the wording.
            $safeMessages = [
                400 => 'Bad request.',
                401 => 'Unauthorized: Admin session required.',
                403 => 'Forbidden.',
                404 => 'Not found.',
                405 => 'Method not allowed.',
                409 => 'That change conflicts with the current data.',
                422 => 'The submitted data could not be processed.',
                429 => 'Too many requests. Please slow down and try again shortly.'
            ];

            admin_error(
                $safeMessages[$status] ?? 'An unexpected error occurred.',
                $status,
                admin_debug_details($exception)
            );
        });

        // A PHP warning rendered into the middle of a JSON body both breaks the
        // parse and prints the server's directory layout. It goes to the log.
        if (!filter_var((string) EnvHelper::get('APP_DEBUG', ''), FILTER_VALIDATE_BOOLEAN)) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
        }

        ini_set('log_errors', '1');
    }
}

if (!function_exists('admin_db')) {
    function admin_db(): mysqli
    {
        return booth_db();
    }
}

if (!function_exists('admin_json_response')) {
    function admin_json_response(array $payload, int $status = 200): void
    {
        booth_json_response($payload, $status);
    }
}

if (!function_exists('admin_success')) {
    function admin_success(string $message, array $data = [], int $status = 200): void
    {
        $data['csrfToken'] = admin_get_csrf_token();
        booth_success($message, $data, $status);
    }
}

if (!function_exists('admin_error')) {
    function admin_error(string $message, int $status = 400, array $data = []): void
    {
        booth_error($message, $status, $data);
    }
}

if (!function_exists('admin_debug_details')) {
    /**
     * The 'details' bag on a failed admin response.
     *
     * These endpoints used to hand the raw exception text back to the browser,
     * which on a query failure is the mysqli message -- table and column names,
     * and the fragment of SQL that broke. That is a map of the schema handed to
     * anyone who can provoke a 500. The text still goes to the error log via
     * admin_log() on every path; it only reaches the client when APP_DEBUG is
     * on in .env, which it is not in a deployed copy.
     */
    function admin_debug_details(Throwable $exception): array
    {
        return booth_debug_details($exception);
    }
}

if (!function_exists('admin_safe_error_message')) {
    /**
     * Some endpoints raise their own RuntimeException to explain a refusal
     * ("Floor still has active reservations") and re-use that text as the
     * response message. That is fine for a 4xx the endpoint authored, but a
     * 5xx message is whatever the driver threw, so it is replaced with the
     * caller's generic fallback.
     */
    function admin_safe_error_message(Throwable $exception, int $status, string $fallback): string
    {
        if ($status >= 500) {
            return $fallback;
        }

        $message = trim($exception->getMessage());

        return $message !== '' ? $message : $fallback;
    }
}

if (!function_exists('admin_log')) {
    function admin_log(string $message, array $context = []): void
    {
        booth_log('[admin] ' . $message, $context);
    }
}

if (!function_exists('admin_request_data')) {
    function admin_request_data(): array
    {
        static $payload = null;

        if (is_array($payload)) {
            return $payload;
        }

        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $rawBody = file_get_contents('php://input') ?: '';
        $payload = [];

        if (stripos($contentType, 'application/json') !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $payload = is_array($decoded) ? $decoded : [];
        } elseif (!empty($_POST)) {
            $payload = $_POST;
        } elseif ($rawBody !== '') {
            parse_str($rawBody, $parsedBody);
            $payload = is_array($parsedBody) ? $parsedBody : [];
        }

        return $payload;
    }
}

if (!function_exists('admin_input')) {
    function admin_input(string $key, $default = null)
    {
        $data = admin_request_data();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }
}

if (!function_exists('admin_method')) {
    function admin_method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }
}

if (!function_exists('admin_require_method')) {
    function admin_require_method(string $expectedMethod): void
    {
        if (admin_method() !== strtoupper($expectedMethod)) {
            admin_error('Method not allowed.', 405);
        }
    }
}

if (!function_exists('admin_clean_text')) {
    function admin_clean_text($value): string
    {
        return trim((string) ($value ?? ''));
    }
}

if (!function_exists('admin_bool')) {
    function admin_bool($value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
}

if (!function_exists('admin_float')) {
    function admin_float($value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}

if (!function_exists('admin_staff_password_hash')) {
    function admin_staff_password_hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('admin_staff_password_is_legacy_hash')) {
    /**
     * A bare SHA-256 hex digest, from before staff passwords used password_hash().
     */
    function admin_staff_password_is_legacy_hash(string $hash): bool
    {
        return strlen($hash) === 64 && ctype_xdigit($hash);
    }
}

if (!function_exists('admin_staff_password_verify')) {
    function admin_staff_password_verify(string $password, string $hash): bool
    {
        // Support both old SHA256 hashes and new password_hash() for migration.
        // Callers that have the account id should follow a success with
        // admin_staff_password_upgrade_hash(), which retires the old digest.
        if (admin_staff_password_is_legacy_hash($hash)) {
            return hash_equals($hash, hash('sha256', $password));
        }
        return password_verify($password, $hash);
    }
}

if (!function_exists('admin_staff_password_upgrade_hash')) {
    /**
     * Re-hash a legacy password the moment its owner proves they know it.
     *
     * Unsalted SHA-256 is one GPU-hour away from plaintext for any password a
     * person would actually choose, so a leaked staff_accounts table gave up
     * every admin credential in it. The compatibility branch above had to stay
     * -- dropping it would have locked out every account created before the
     * migration -- but it only has to survive until each account holder signs
     * in once. This is what makes that happen: the verified plaintext is in
     * hand exactly here and nowhere else, so this is the only point where the
     * upgrade is possible without a password reset.
     *
     * Best effort by design. A failed UPDATE must not fail the login; the old
     * hash still verifies and the upgrade is retried on the next sign-in.
     */
    function admin_staff_password_upgrade_hash(mysqli $connection, int $staffId, string $password, string $currentHash): void
    {
        if ($staffId <= 0 || !admin_staff_password_is_legacy_hash($currentHash)) {
            return;
        }

        try {
            $newHash = admin_staff_password_hash($password);

            $statement = $connection->prepare(
                'UPDATE staff_accounts SET password_hash = ? WHERE id = ?'
            );
            $statement->bind_param('si', $newHash, $staffId);
            $statement->execute();
            $statement->close();

            admin_log('admin-password-hash-upgraded', ['staff_id' => $staffId]);
        } catch (Throwable $exception) {
            admin_log('admin-password-hash-upgrade-failed', [
                'staff_id' => $staffId,
                'error' => $exception->getMessage()
            ]);
        }
    }
}

if (!function_exists('admin_require_auth')) {
    function admin_require_auth(string $requiredRole = 'admin', ?string $permission = null): array
    {
        // Rate limiting for admin actions. enforce() throws on the way past,
        // and this runs before the session check, so an unauthenticated flood
        // is refused here rather than reaching the database.
        $rateLimitKey = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        try {
            RateLimiter::enforce('admin_action', $rateLimitKey);
        } catch (RuntimeException $rateLimitException) {
            $retryAfter = RateLimiter::getResetTime('admin_action', $rateLimitKey);

            if (!headers_sent()) {
                header('Retry-After: ' . max(1, $retryAfter));
            }

            admin_error('Too many requests. Please slow down and try again shortly.', 429, [
                'retryAfter' => max(1, $retryAfter)
            ]);
        }

        $currentTime = time();

        // Check if admin session exists
        if (empty($_SESSION['sndra_admin'])) {
            admin_error('Unauthorized: Admin session required.', 401);
        }

        $admin = $_SESSION['sndra_admin'];

        // Validate session structure
        if (!isset($admin['id'], $admin['role'], $admin['email'])) {
            session_destroy();
            admin_error('Unauthorized: Invalid session data.', 401);
        }

        // RBAC check if permission specified
        if ($permission) {
            try {
                RBACMiddleware::authorize($permission, $admin['id']);
            } catch (RuntimeException $e) {
                admin_error('Forbidden: ' . $e->getMessage(), 403);
            }
        }

        // Check role.
        //
        // A supervisor is an admin who may look but not touch: they satisfy an
        // 'admin' requirement on reads and are refused on anything that
        // changes state. Every mutating admin endpoint is a POST, so the method
        // is the whole test -- and it holds for endpoints written later without
        // anyone having to remember this rule.
        $isSupervisor = $admin['role'] === 'supervisor';

        if ($isSupervisor && $requiredRole === 'admin') {
            if (admin_method() !== 'GET') {
                admin_error('Forbidden: supervisors have read-only access.', 403);
            }
        } elseif ($admin['role'] !== $requiredRole && $requiredRole !== '*') {
            admin_error('Forbidden: Insufficient permissions for this action.', 403);
        }

        // Check session timeout (30 minutes default)
        $sessionTimeout = (int) ini_get('session.gc_maxlifetime') ?: ADMIN_SESSION_TIMEOUT;
        if (isset($_SESSION['_admin_last_activity'])) {
            if ($currentTime - $_SESSION['_admin_last_activity'] > $sessionTimeout) {
                session_destroy();
                admin_error('Unauthorized: Session expired.', 401);
            }
        }

        // Update last activity timestamp
        $_SESSION['_admin_last_activity'] = $currentTime;

        return [
            'id' => (int) $admin['id'],
            'role' => $admin['role'],
            'email' => $admin['email'],
            'fullName' => $admin['fullName'] ?? 'Admin'
        ];
    }
}

if (!function_exists('admin_mask_email')) {
    /** j***@example.com -- enough to recognise, not enough to harvest. */
    function admin_mask_email(string $email): string
    {
        $parts = explode('@', trim($email), 2);

        if (count($parts) !== 2 || $parts[0] === '') {
            return 'your email';
        }

        $visible = mb_substr($parts[0], 0, 1);

        return $visible . str_repeat('*', max(3, mb_strlen($parts[0]) - 1)) . '@' . $parts[1];
    }
}

if (!function_exists('admin_establish_session')) {
    /**
     * Everything that turns a verified staff row into a signed-in session.
     *
     * Shared by login.php and verify-2fa.php so the two paths cannot drift --
     * a second factor that skipped the session regeneration or the audit entry
     * would be worse than no second factor at all.
     */
    function admin_establish_session(mysqli $connection, array $staff, string $fallbackEmail = ''): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);
        $csrfToken = CsrfMiddleware::refresh();

        $staffId = (int) ($staff['id'] ?? 0);
        $staffRole = (string) ($staff['role'] ?? 'admin');
        $fullName = (string) ($staff['full_name'] ?? 'Administrator');
        $staffEmail = (string) ($staff['email'] ?? $fallbackEmail);

        $_SESSION['sndra_admin'] = [
            'id' => $staffId,
            'role' => $staffRole,
            'fullName' => $fullName,
            'email' => $staffEmail
        ];
        $_SESSION['_admin_last_activity'] = time();
        unset($_SESSION['admin_pending_2fa']);

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

        return [
            'success' => true,
            'message' => 'Login successful.',
            'redirect' => 'admin-dashboard.html',
            'role' => $staffRole,
            'data' => [
                'id' => $staffId,
                'role' => $staffRole,
                'fullName' => $fullName,
                'email' => $staffEmail,
                'token' => session_id(),
                'csrfToken' => $csrfToken
            ]
        ];
    }
}

if (!function_exists('admin_start_two_factor_challenge')) {
    /**
     * Issue the second factor.
     *
     * The code is emailed and only its hash is kept, next to an expiry and an
     * attempt counter, so the session cannot be read for the answer. Returns
     * false when the mail could not be sent -- the caller then lets the login
     * through rather than locking the only administrator out of the system
     * because SMTP is down.
     */
    function admin_start_two_factor_challenge(array $staff): bool
    {
        require_once __DIR__ . '/../common/mailer.php';

        $email = trim((string) ($staff['email'] ?? ''));

        if ($email === '') {
            return false;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['admin_pending_2fa'] = [
            'staff_id' => (int) ($staff['id'] ?? 0),
            'email' => $email,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + 300,
            'attempts' => 0
        ];

        $sent = sndra_mail_send(
            $email,
            'Your SNDRA Park admin sign-in code',
            sndra_mail_layout(
                'Admin sign-in code',
                'Someone is signing in to the SNDRA Park admin dashboard. Enter this code to continue.',
                ['Expires' => '5 minutes from now', 'Account' => $email],
                $code,
                'If this was not you, change the admin password immediately.'
            )
        );

        if (!$sent) {
            unset($_SESSION['admin_pending_2fa']);
        }

        return $sent;
    }
}

if (!function_exists('admin_get_settings_map')) {
    function admin_get_settings_map(mysqli $connection): array
    {
        return system_settings_fetch($connection);
    }
}

if (!function_exists('admin_floor_sort_order')) {
    function admin_floor_sort_order(string $floorName): int
    {
        $map = [
            'LG' => 1,
            '1st Floor' => 2,
            '2nd Floor' => 3,
            '3rd Floor' => 4,
            '4th Floor' => 5,
            '5th Floor' => 6
        ];

        return $map[$floorName] ?? 99;
    }
}

if (!function_exists('admin_get_csrf_token')) {
    function admin_get_csrf_token(): string
    {
        return CsrfMiddleware::getToken();
    }
}

if (!function_exists('admin_csrf_input')) {
    function admin_csrf_input(): string
    {
        return CsrfMiddleware::getInputField();
    }
}

if (!function_exists('admin_validate_csrf')) {
    function admin_validate_csrf(): void
    {
        try {
            CsrfMiddleware::validate();
        } catch (RuntimeException $e) {
            // A rejected token used to surface as a 500. The middleware throws
            // 419, which Apache rewrites to 500 because it has no reason
            // phrase for it, so the answer is pinned to 403: the client can
            // tell a refused request from a server fault and re-authenticate.
            admin_error('Security validation failed: ' . $e->getMessage(), 403);
        }
    }
}

if (!function_exists('admin_require_csrf')) {
    function admin_require_csrf(): void
    {
        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        
        // Only check CSRF for state-changing operations
        if (in_array($requestMethod, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            admin_validate_csrf();
        }
    }
}

// ---------------------------------------------------------------------------
// Bootstrap. This runs last so every helper above is defined first: the
// exception handler installed here calls admin_log() and admin_error(), and
// registering it earlier left a window where a throw from session_start() or
// the CSRF initialiser would hit an undefined function instead of the handler.
// ---------------------------------------------------------------------------

admin_register_exception_handler();

admin_prepare_session_storage();
admin_harden_session_cookie();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

admin_send_security_headers();

// Initialize CSRF protection for admin endpoints
CsrfMiddleware::initialize();
