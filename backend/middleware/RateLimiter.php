<?php

declare(strict_types=1);

/**
 * Rate Limiter - Prevents brute force attacks and abuse
 * Supports multiple strategies: fixed window, sliding window, token bucket
 */
class RateLimiter
{
    private const TABLE_NAME = 'rate_limit_attempts';
    private const CLEANUP_INTERVAL = 3600; // 1 hour
    private static $schemaEnsured = false;
    private static $lastCleanupAt = 0;
    private static $usesSecuritySchema = null;

    private static $limits = [
        'login' => ['max_attempts' => 5, 'window_seconds' => 900], // 5 attempts per 15 minutes
        'otp_request' => ['max_attempts' => 3, 'window_seconds' => 300], // 3 OTP requests per 5 minutes
        'admin_action' => ['max_attempts' => 100, 'window_seconds' => 60], // 100 admin actions per minute
        'api_call' => ['max_attempts' => 1000, 'window_seconds' => 60], // 1000 API calls per minute per IP
        'reservation_create' => ['max_attempts' => 10, 'window_seconds' => 300], // 10 reservations per 5 minutes
    ];

    /**
     * Check if action is allowed under rate limiting
     */
    public static function check(string $action, ?string $identifier = null, ?string $secondaryIdentifier = null): bool
    {
        if (!isset(self::$limits[$action])) {
            return true; // No limit defined for this action
        }

        $identifier = $identifier ?: self::getClientIdentifier();
        if ($secondaryIdentifier) {
            $identifier = sprintf('%s|%s', $identifier, $secondaryIdentifier);
        }
        $limit = self::$limits[$action];

        $connection = self::getConnection();
        if (!$connection) {
            return true; // Database unavailable, allow action
        }

        // Clean up old entries periodically
        self::cleanupOldEntries($connection);

        // Count attempts in current window
        $attempts = self::countAttempts($connection, $action, $identifier, $limit['window_seconds']);

        if ($attempts >= $limit['max_attempts']) {
            return false; // Rate limit exceeded
        }

        // Record this attempt
        self::recordAttempt($connection, $action, $identifier);

        return true;
    }

    /**
     * Check rate limit and throw exception if exceeded
     */
    public static function enforce(string $action, ?string $identifier = null, ?string $secondaryIdentifier = null): void
    {
        if (!self::check($action, $identifier, $secondaryIdentifier)) {
            $limit = self::$limits[$action] ?? ['max_attempts' => 0, 'window_seconds' => 0];
            $resetTime = time() + $limit['window_seconds'];

            // Use standard RuntimeException signature (message, code)
            throw new RuntimeException(
                "Rate limit exceeded for action '{$action}'. Try again later.",
                429
            );
        }
    }

    /**
     * Get remaining attempts for an action
     */
    public static function getRemainingAttempts(string $action, ?string $identifier = null, ?string $secondaryIdentifier = null): int
    {
        if (!isset(self::$limits[$action])) {
            return PHP_INT_MAX;
        }

        $identifier = $identifier ?: self::getClientIdentifier();
        $limit = self::$limits[$action];

        $connection = self::getConnection();
        if (!$connection) {
            return $limit['max_attempts'];
        }

        $attempts = self::countAttempts($connection, $action, $identifier, $limit['window_seconds']);
        return max(0, $limit['max_attempts'] - $attempts);
    }

    /**
     * Get time until rate limit resets (in seconds)
     */
    public static function getResetTime(string $action, ?string $identifier = null, ?string $secondaryIdentifier = null): int
    {
        if (!isset(self::$limits[$action])) {
            return 0;
        }

        $identifier = $identifier ?: self::getClientIdentifier();
        $limit = self::$limits[$action];

        $connection = self::getConnection();
        if (!$connection) {
            return 0;
        }

        $oldestAttempt = self::getOldestAttemptTime($connection, $action, $identifier);
        if ($oldestAttempt === null) {
            return 0;
        }

        $resetTime = $oldestAttempt + $limit['window_seconds'];
        return max(0, $resetTime - time());
    }

    /**
     * Get client identifier (IP address or user ID)
     */
    private static function getClientIdentifier(): string
    {
        // Use user ID if logged in, otherwise IP address
        if (isset($_SESSION['user_id'])) {
            return 'user_' . $_SESSION['user_id'];
        }

        if (isset($_SESSION['sndra_admin']['id'])) {
            return 'admin_' . $_SESSION['sndra_admin']['id'];
        }

        // Use IP address for anonymous users
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ??
               $_SERVER['HTTP_X_REAL_IP'] ??
               $_SERVER['REMOTE_ADDR'] ??
               'unknown';
    }

    /**
     * Get database connection
     */
    private static function getConnection()
    {
        try {
            $connection = Database::connection();
            self::ensureSchema($connection);
            return $connection;
        } catch (Exception $e) {
            return null; // Fail gracefully
        }
    }

    /**
     * Ensure the lightweight rate limiting table exists for both API and admin flows.
     */
    private static function ensureSchema($connection): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        $connection->query("
            CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(100) NOT NULL,
                identifier VARCHAR(255) NOT NULL,
                created_at INT NOT NULL,
                INDEX idx_rate_limit_action_identifier_created (action, identifier, created_at),
                INDEX idx_rate_limit_created_at (created_at)
            )
        ");

        self::$schemaEnsured = true;
    }

    /**
     * Count attempts in current window
     */
    private static function countAttempts($connection, string $action, string $identifier, int $windowSeconds): int
    {
        if (self::usesSecuritySchema($connection)) {
            $stmt = $connection->prepare("
                SELECT COALESCE(SUM(attempt_count), 0) as count
                FROM " . self::TABLE_NAME . "
                WHERE action_type = ?
                  AND identifier = ?
                  AND window_end > NOW()
                  AND (blocked_until IS NULL OR blocked_until <= NOW())
            ");
            $stmt->bind_param('ss', $action, $identifier);
            $stmt->execute();
            $result = $stmt->get_result();

            return (int) ($result->fetch_assoc()['count'] ?? 0);
        }

        $cutoffTime = time() - $windowSeconds;

        $stmt = $connection->prepare("
            SELECT COUNT(*) as count
            FROM " . self::TABLE_NAME . "
            WHERE action = ? AND identifier = ? AND created_at >= ?
        ");
        $stmt->bind_param('ssi', $action, $identifier, $cutoffTime);
        $stmt->execute();
        $result = $stmt->get_result();

        return (int) ($result->fetch_assoc()['count'] ?? 0);
    }

    /**
     * Record an attempt
     */
    private static function recordAttempt($connection, string $action, string $identifier): void
    {
        if (self::usesSecuritySchema($connection)) {
            $limit = self::$limits[$action] ?? ['window_seconds' => 60];
            $windowEnd = date('Y-m-d H:i:s', time() + (int) $limit['window_seconds']);

            $stmt = $connection->prepare("
                INSERT INTO " . self::TABLE_NAME . "
                (identifier, action_type, attempt_count, window_start, window_end, last_attempt_at, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    attempt_count = attempt_count + 1,
                    window_end = VALUES(window_end),
                    last_attempt_at = NOW(),
                    updated_at = NOW()
            ");
            $stmt->bind_param('sss', $identifier, $action, $windowEnd);
            $stmt->execute();
            return;
        }

        $stmt = $connection->prepare("
            INSERT INTO " . self::TABLE_NAME . " (action, identifier, created_at)
            VALUES (?, ?, ?)
        ");
        $timestamp = time();
        $stmt->bind_param('ssi', $action, $identifier, $timestamp);
        $stmt->execute();
    }

    /**
     * Get oldest attempt time for reset calculation
     */
    private static function getOldestAttemptTime($connection, string $action, string $identifier): ?int
    {
        if (self::usesSecuritySchema($connection)) {
            $stmt = $connection->prepare("
                SELECT MIN(UNIX_TIMESTAMP(window_start)) AS created_at
                FROM " . self::TABLE_NAME . "
                WHERE action_type = ? AND identifier = ? AND window_end > NOW()
            ");
            $stmt->bind_param('ss', $action, $identifier);
            $stmt->execute();
            $result = $stmt->get_result();

            $row = $result->fetch_assoc();
            return $row && $row['created_at'] !== null ? (int) $row['created_at'] : null;
        }

        $stmt = $connection->prepare("
            SELECT created_at
            FROM " . self::TABLE_NAME . "
            WHERE action = ? AND identifier = ?
            ORDER BY created_at ASC
            LIMIT 1
        ");
        $stmt->bind_param('ss', $action, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        $row = $result->fetch_assoc();
        return $row ? (int) $row['created_at'] : null;
    }

    /**
     * Clean up old entries to prevent table bloat
     */
    private static function cleanupOldEntries($connection): void
    {
        // Only cleanup once per hour to avoid overhead
        if ((time() - self::$lastCleanupAt) < self::CLEANUP_INTERVAL) {
            return;
        }

        if (self::usesSecuritySchema($connection)) {
            $connection->query("
                DELETE FROM " . self::TABLE_NAME . "
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
            ");
        } else {
            $cutoffTime = time() - 86400;
            $connection->query("DELETE FROM " . self::TABLE_NAME . " WHERE created_at < {$cutoffTime}");
        }

        self::$lastCleanupAt = time();
    }

    /**
     * Get rate limit configuration for an action
     */
    public static function getLimit(string $action): ?array
    {
        return self::$limits[$action] ?? null;
    }

    /**
     * Set custom rate limit for an action
     */
    public static function setLimit(string $action, int $maxAttempts, int $windowSeconds): void
    {
        self::$limits[$action] = [
            'max_attempts' => $maxAttempts,
            'window_seconds' => $windowSeconds
        ];
    }

    private static function usesSecuritySchema($connection): bool
    {
        if (self::$usesSecuritySchema !== null) {
            return self::$usesSecuritySchema;
        }

        $result = $connection->query("
            SHOW COLUMNS FROM " . self::TABLE_NAME . " LIKE 'action_type'
        ");

        self::$usesSecuritySchema = (bool) ($result && $result->num_rows > 0);
        return self::$usesSecuritySchema;
    }
}
