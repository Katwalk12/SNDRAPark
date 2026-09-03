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

admin_prepare_session_storage();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Initialize CSRF protection for admin endpoints
CsrfMiddleware::initialize();

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

if (!function_exists('admin_staff_password_verify')) {
    function admin_staff_password_verify(string $password, string $hash): bool
    {
        // Support both old SHA256 hashes and new password_hash() for migration
        if (strlen($hash) === 64 && ctype_xdigit($hash)) {
            return hash_equals($hash, hash('sha256', $password));
        }
        return password_verify($password, $hash);
    }
}

if (!function_exists('admin_require_auth')) {
    function admin_require_auth(string $requiredRole = 'admin', ?string $permission = null): array
    {
        // Rate limiting for admin actions
        RateLimiter::enforce('admin_action', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

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
        $sessionTimeout = (int) ini_get('session.gc_maxlifetime') ?: 1800;
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
