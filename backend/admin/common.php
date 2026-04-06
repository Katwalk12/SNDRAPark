<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/system-settings.php';
require_once __DIR__ . '/../middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../middleware/RBACMiddleware.php';
require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/audit-log.php';

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

        // Check role
        if ($admin['role'] !== $requiredRole && $requiredRole !== '*') {
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
            admin_error('Security validation failed: ' . $e->getMessage(), (int) $e->getCode());
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
