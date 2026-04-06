<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/AuditLogger.php';

class SessionManager
{
    private static $sessionTimeout = 1800; // 30 minutes default
    private static $settingsLoaded = false;
    private static $sessionStoragePrepared = false;

    /**
     * Initialize session with security settings
     */
    public static function init(): void
    {
        self::prepareSessionStorage();

        if (session_status() === PHP_SESSION_NONE) {
            // Set secure session parameters
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.gc_maxlifetime', self::getSessionTimeout());

            session_start();
        }

        self::loadSettings();
        self::validateSession();
        self::regenerateSessionIfNeeded();
    }

    /**
     * Create a new session for a user
     *
     * @param array $user User data
     * @return bool True if session created successfully
     */
    public static function createSession(array $user): bool
    {
        self::prepareSessionStorage();
        self::loadSettings();

        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.gc_maxlifetime', self::getSessionTimeout());
            session_start();
        }

        // Clear any existing session data
        session_unset();

        // Regenerate session ID for security
        session_regenerate_id(true);

        // Set session data
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'] ?? 'user';
        $_SESSION['user_name'] = $user['full_name'] ?? trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['session_expires'] = time() + self::getSessionTimeout();
        $_SESSION['ip_address'] = self::getClientIP();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Update user's last login and session expiry in database
        self::updateUserSession($user['id']);

        // Log successful login
        AuditLogger::log('login', 'auth', 'success',
            "Session created for user {$user['email']}", ['user_id' => $user['id']]);

        return true;
    }

    /**
     * Validate current session
     *
     * @return bool True if session is valid
     * @throws RuntimeException If session is invalid or expired
     */
    public static function validateSession(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Session not active.', 401);
        }

        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            throw new RuntimeException('Not authenticated.', 401);
        }

        // Check session timeout
        if (self::isSessionExpired()) {
            self::destroySession();
            AuditLogger::log('session_expired', 'session', 'warning',
                "Session expired for user {$_SESSION['user_id']}", ['user_id' => $_SESSION['user_id']]);
            throw new RuntimeException('Session expired.', 401);
        }

        // Check IP address consistency (optional security feature)
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== self::getClientIP()) {
            self::destroySession();
            AuditLogger::log('ip_address_changed', 'session', 'warning',
                "IP address changed for user {$_SESSION['user_id']}", ['user_id' => $_SESSION['user_id']]);
            throw new RuntimeException('Session security violation.', 401);
        }

        // Update last activity
        $_SESSION['last_activity'] = time();
        $_SESSION['session_expires'] = time() + self::getSessionTimeout();

        return true;
    }

    /**
     * Check if session has expired
     *
     * @return bool True if session is expired
     */
    public static function isSessionExpired(): bool
    {
        if (!isset($_SESSION['session_expires'])) {
            return true;
        }

        return time() > $_SESSION['session_expires'];
    }

    /**
     * Regenerate session ID periodically for security
     */
    private static function regenerateSessionIfNeeded(): void
    {
        // Regenerate session ID every 30 minutes
        $regenerateInterval = 1800; // 30 minutes

        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        }

        if (time() - $_SESSION['last_regeneration'] > $regenerateInterval) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();

            AuditLogger::log('session_regenerated', 'session', 'success',
                "Session ID regenerated for user {$_SESSION['user_id']}", ['user_id' => $_SESSION['user_id']]);
        }
    }

    /**
     * Destroy current session
     */
    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $userId = $_SESSION['user_id'] ?? null;

            // Clear session data
            session_unset();

            // Destroy session cookie
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }

            // Destroy session
            session_destroy();

            if ($userId) {
                // Clear session expiry in database
                self::clearUserSession($userId);

                AuditLogger::log('logout', 'auth', 'success',
                    'Session destroyed', ['user_id' => $userId]);
            }
        }
    }

    /**
     * Get current session data
     *
     * @return array|null Session data or null if no active session
     */
    public static function getSessionData(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
            return null;
        }

        return [
            'user_id' => $_SESSION['user_id'],
            'user_email' => $_SESSION['user_email'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
            'user_role' => $_SESSION['user_role'] ?? 'user',
            'login_time' => $_SESSION['login_time'] ?? null,
            'last_activity' => $_SESSION['last_activity'] ?? null,
            'expires_at' => $_SESSION['session_expires'] ?? null,
            'time_remaining' => self::getTimeRemaining()
        ];
    }

    /**
     * Get remaining session time in seconds
     *
     * @return int|null Seconds remaining or null if no session
     */
    public static function getTimeRemaining(): ?int
    {
        if (!isset($_SESSION['session_expires'])) {
            return null;
        }

        $remaining = $_SESSION['session_expires'] - time();
        return max(0, $remaining);
    }

    /**
     * Extend session timeout
     *
     * @param int|null $additionalSeconds Additional seconds to add
     */
    public static function extendSession(?int $additionalSeconds = null): void
    {
        $secondsToAdd = $additionalSeconds ?? self::getSessionTimeout();

        $_SESSION['session_expires'] = time() + $secondsToAdd;
        $_SESSION['last_activity'] = time();
    }

    /**
     * Get session timeout setting
     *
     * @return int Timeout in seconds
     */
    public static function getSessionTimeout(): int
    {
        if (!self::$settingsLoaded) {
            self::loadSettings();
        }

        return self::$sessionTimeout;
    }

    /**
     * Load security settings from database
     */
    private static function loadSettings(): void
    {
        if (self::$settingsLoaded) {
            return;
        }

        try {
            $connection = Database::connection();
            $result = $connection->query("
                SELECT setting_key, setting_value, setting_type
                FROM security_settings
                WHERE setting_key IN ('session_timeout_minutes', 'enable_session_timeout')
            ");

            while ($row = $result->fetch_assoc()) {
                if ($row['setting_key'] === 'session_timeout_minutes') {
                    self::$sessionTimeout = (int) $row['setting_value'] * 60; // Convert to seconds
                }
            }

            self::$settingsLoaded = true;
        } catch (Exception $e) {
            error_log("SessionManager::loadSettings failed: " . $e->getMessage());
            // Use defaults
            self::$sessionTimeout = 1800; // 30 minutes
            self::$settingsLoaded = true;
        }
    }

    /**
     * Update user's session information in database
     */
    private static function updateUserSession(int $userId): void
    {
        try {
            $connection = Database::connection();
            $expiresAt = date('Y-m-d H:i:s', time() + self::getSessionTimeout());

            $stmt = $connection->prepare("
                UPDATE users
                SET last_login_at = NOW(), session_expires_at = ?
                WHERE id = ?
            ");

            $stmt->bind_param('si', $expiresAt, $userId);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("SessionManager::updateUserSession failed: " . $e->getMessage());
        }
    }

    /**
     * Clear user's session information in database
     */
    private static function clearUserSession(int $userId): void
    {
        try {
            $connection = Database::connection();

            $stmt = $connection->prepare("
                UPDATE users
                SET session_expires_at = NULL
                WHERE id = ?
            ");

            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("SessionManager::clearUserSession failed: " . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     */
    private static function getClientIP(): string
    {
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Check if session timeout is enabled
     */
    public static function isSessionTimeoutEnabled(): bool
    {
        try {
            $connection = Database::connection();
            $result = $connection->query("
                SELECT setting_value FROM security_settings
                WHERE setting_key = 'enable_session_timeout' LIMIT 1
            ");

            if ($row = $result->fetch_assoc()) {
                return in_array(strtolower($row['setting_value']), ['true', '1', 'yes', 'on']);
            }

            return true; // Default to enabled
        } catch (Exception $e) {
            error_log("SessionManager::isSessionTimeoutEnabled failed: " . $e->getMessage());
            return true;
        }
    }

    private static function prepareSessionStorage(): void
    {
        if (self::$sessionStoragePrepared) {
            return;
        }

        $configuredPath = session_save_path();
        $activePath = $configuredPath !== '' ? $configuredPath : sys_get_temp_dir();

        if ($activePath === '' || !is_dir($activePath) || !is_writable($activePath)) {
            $fallbackPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions';

            if (!is_dir($fallbackPath)) {
                mkdir($fallbackPath, 0777, true);
            }

            if (is_dir($fallbackPath) && is_writable($fallbackPath)) {
                session_save_path($fallbackPath);
            }
        }

        self::$sessionStoragePrepared = true;
    }
}
