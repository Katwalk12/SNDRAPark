<?php

declare(strict_types=1);

/**
 * Audit Logger - Comprehensive logging for security events and admin actions
 * Logs all critical actions for compliance and security monitoring
 */
class AuditLogger
{
    private const TABLE_NAME = 'audit_log';
    private const LOG_LEVELS = [
        'DEBUG' => 1,
        'INFO' => 2,
        'WARNING' => 3,
        'ERROR' => 4,
        'CRITICAL' => 5
    ];

    private const EVENT_TYPES = [
        // Authentication events
        'LOGIN_SUCCESS' => 'User successfully logged in',
        'LOGIN_FAILED' => 'Login attempt failed',
        'LOGOUT' => 'User logged out',
        'ADMIN_LOGIN_SUCCESS' => 'Admin successfully logged in',
        'ADMIN_LOGIN_FAILED' => 'Admin login attempt failed',
        'ADMIN_LOGOUT' => 'Admin logged out',

        // User management
        'USER_REGISTERED' => 'New user registered',
        'USER_PROFILE_UPDATED' => 'User profile updated',
        'USER_PASSWORD_CHANGED' => 'User password changed',
        'USER_ACCOUNT_LOCKED' => 'User account locked',
        'USER_ACCOUNT_UNLOCKED' => 'User account unlocked',
        'USER_DELETED' => 'User account deleted',

        // Reservation events
        'RESERVATION_CREATED' => 'Parking reservation created',
        'RESERVATION_CANCELLED' => 'Reservation cancelled',
        'RESERVATION_EXPIRED' => 'Reservation expired',
        'RESERVATION_CHECKED_IN' => 'Reservation checked in at booth',

        // Payment events
        'PAYMENT_PROCESSED' => 'Payment processed successfully',
        'PAYMENT_FAILED' => 'Payment processing failed',
        'PAYMENT_REFUNDED' => 'Payment refunded',

        // Admin actions
        'ADMIN_USER_UPDATED' => 'Admin updated user account',
        'ADMIN_SLOT_ADDED' => 'Admin added parking slot',
        'ADMIN_SLOT_DELETED' => 'Admin deleted parking slot',
        'ADMIN_FLOOR_ADDED' => 'Admin added parking floor',
        'ADMIN_FLOOR_DELETED' => 'Admin deleted parking floor',
        'ADMIN_SETTINGS_UPDATED' => 'Admin updated system settings',
        'ADMIN_NOTIFICATION_SENT' => 'Admin sent notification',

        // Security events
        'RATE_LIMIT_EXCEEDED' => 'Rate limit exceeded',
        'CSRF_VIOLATION' => 'CSRF token validation failed',
        'SUSPICIOUS_ACTIVITY' => 'Suspicious activity detected',
        'BRUTE_FORCE_ATTEMPT' => 'Potential brute force attack detected',

        // System events
        'SYSTEM_BACKUP' => 'System backup completed',
        'SYSTEM_MAINTENANCE' => 'System maintenance performed',
        'CONFIGURATION_CHANGED' => 'System configuration changed'
    ];

    /**
     * Log an audit event
     */
    public static function log(
        string $eventType,
        $arg2 = 'INFO',
        $arg3 = [],
        $arg4 = null,
        $arg5 = null
    ): bool {
        try {
            $connection = self::getConnection();
            if (!$connection) {
                return false; // Database unavailable
            }

            self::ensureSchema($connection);

            [$level, $context, $userId, $ipAddress] = self::normalizeLogArguments(
                $arg2,
                $arg3,
                $arg4,
                $arg5
            );

            // Auto-detect user and IP if not provided
            $userId = $userId ?? self::getCurrentUserId();
            $ipAddress = $ipAddress ?? self::getClientIp();

            // Validate event type
            if (!isset(self::EVENT_TYPES[$eventType])) {
                $eventType = 'UNKNOWN_EVENT';
            }

            // Validate log level
            $level = strtoupper($level);
            if (!isset(self::LOG_LEVELS[$level])) {
                $level = 'INFO';
            }

            // Prepare context data
            $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($contextJson) > 65535) { // TEXT column limit
                $contextJson = json_encode(['truncated' => true, 'original_size' => strlen($contextJson)]);
            }

            // Insert audit log
            $stmt = $connection->prepare("
                INSERT INTO " . self::TABLE_NAME . "
                (user_id, admin_id, action, resource_type, resource_id, old_values, new_values, ip_address, user_agent, session_id, status, details)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $sessionId = session_id() ?: null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $resourceType = $context['target_type'] ?? $context['category'] ?? 'system';
            $resourceId = isset($context['target_id']) ? (string) $context['target_id'] : (isset($context['resource_id']) ? (string) $context['resource_id'] : null);
            $oldValues = isset($context['old_data']) ? json_encode($context['old_data']) : null;
            $newValues = isset($context['new_data']) ? json_encode($context['new_data']) : null;
            $details = isset($context['message']) ? (string) $context['message'] : (isset($context['details']) ? (string) $context['details'] : json_encode($context));
            $status = strtolower($level) === 'error' ? 'failure' : (strtolower($level) === 'warning' ? 'warning' : 'success');
            $adminId = isset($context['admin_id']) ? (int) $context['admin_id'] : null;

            $stmt->bind_param(
                'iissssssssss',
                $userId,
                $adminId,
                $eventType,
                $resourceType,
                $resourceId,
                $oldValues,
                $newValues,
                $ipAddress,
                $userAgent,
                $sessionId,
                $status,
                $details
            );

            $result = $stmt->execute();

            // Clean up old logs (keep last 90 days)
            self::cleanupOldLogs($connection);

            return $result;

        } catch (Exception $e) {
            // Log to PHP error log as fallback
            error_log("AuditLogger failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log authentication events
     */
    public static function logAuth(string $event, string $username, bool $success = true, array $extra = []): void
    {
        $level = $success ? 'INFO' : 'WARNING';
        $context = array_merge([
            'username' => $username,
            'success' => $success
        ], $extra);

        self::log($event, $level, $context);
    }

    /**
     * Log admin actions (new API)
     */
    public static function logAdminActionNew(string $action, array $details = [], ?int $targetUserId = null): void
    {
        $adminId = self::getCurrentUserId();
        $context = array_merge([
            'admin_id' => $adminId,
            'action_details' => $details
        ], $targetUserId ? ['target_user_id' => $targetUserId] : []);

        self::log($action, 'INFO', $context, $adminId);
    }

    /**
     * Log security violations
     */
    public static function logSecurityViolation(string $violationType, array $details = []): void
    {
        $userId = self::getCurrentUserId();
        $context = array_merge([
            'violation_details' => $details,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ], $details);

        self::log($violationType, 'WARNING', $context, $userId);
    }

    /**
     * Log user actions
     */
    public static function logUserAction(string $action, array $details = []): void
    {
        $userId = self::getCurrentUserId();
        $context = array_merge([
            'action_details' => $details
        ], $details);

        self::log($action, 'INFO', $context, $userId);
    }

    /**
     * Get audit logs with filtering
     */
    public static function getLogs(
        int $limit = 100,
        int $offset = 0,
        ?string $eventType = null,
        ?string $level = null,
        ?int $userId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $connection = self::getConnection();
        if (!$connection) {
            return [];
        }

        $where = [];
        $params = [];
        $types = '';

        if ($eventType) {
            $where[] = 'action = ?';
            $params[] = $eventType;
            $types .= 's';
        }

        if ($level) {
            $where[] = 'status = ?';
            $params[] = strtolower($level) === 'error' ? 'failure' : strtolower($level);
            $types .= 's';
        }

        if ($userId) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
            $types .= 'i';
        }

        if ($dateFrom) {
            $where[] = 'created_at >= ?';
            $params[] = strtotime($dateFrom);
            $types .= 'i';
        }

        if ($dateTo) {
            $where[] = 'created_at <= ?';
            $params[] = strtotime($dateTo);
            $types .= 'i';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $connection->prepare("
            SELECT id, user_id, admin_id, action, resource_type, resource_id, old_values, new_values, ip_address, user_agent, session_id, status, details, created_at
            FROM " . self::TABLE_NAME . "
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            // Convert to expected format
            $row['event_type'] = $row['action'];
            $row['level'] = strtoupper($row['status']);
            $row['context'] = [
                'details' => $row['details'],
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'old_values' => json_decode($row['old_values'] ?? 'null', true),
                'new_values' => json_decode($row['new_values'] ?? 'null', true),
                'user_agent' => $row['user_agent'],
                'session_id' => $row['session_id']
            ];
            $row['created_at_formatted'] = date('Y-m-d H:i:s', strtotime($row['created_at']));
            $logs[] = $row;
        }

        return $logs;
    }

    /**
     * Get audit statistics
     */
    public static function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $connection = self::getConnection();
        if (!$connection) {
            return [];
        }

        $where = '';
        $params = [];
        $types = '';

        if ($dateFrom) {
            $where .= ' AND created_at >= ?';
            $params[] = strtotime($dateFrom);
            $types .= 'i';
        }

        if ($dateTo) {
            $where .= ' AND created_at <= ?';
            $params[] = strtotime($dateTo);
            $types .= 'i';
        }

        $stmt = $connection->prepare("
            SELECT
                action,
                status,
                COUNT(*) as count,
                MAX(created_at) as last_occurrence
            FROM " . self::TABLE_NAME . "
            WHERE 1=1 {$where}
            GROUP BY action, status
            ORDER BY count DESC
        ");

        if ($params) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }

        return $stats;
    }

    /**
     * Backward compatibility method for old API
     * @deprecated Use log() method instead
     */
    public static function logSecurityEvent(string $event, string $category, string $level, string $message, array $metadata = []): void
    {
        $context = array_merge([
            'message' => $message,
            'category' => $category
        ], $metadata);

        self::log(strtoupper(str_replace(' ', '_', $event)), strtoupper($level), $context);
    }

    /**
     * Log admin actions (backward compatibility)
     */
    public static function logAdminAction(?int $adminId, string $action, string $targetType, ?int $targetId, ?array $oldData = null, ?array $newData = null, string $status = 'success', string $details = ''): void
    {
        $context = [
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => $status,
            'details' => $details
        ];

        if ($oldData) $context['old_data'] = $oldData;
        if ($newData) $context['new_data'] = $newData;

        self::log(strtoupper(str_replace(' ', '_', $action)), $status === 'success' ? 'INFO' : 'ERROR', $context, $adminId);
    }

    /**
     * Backward compatibility method for old API
     * @deprecated Use log() method instead
     */
    public static function logApiAccess(string $event, string $service, ?int $userId, string $status, string $details): void
    {
        $context = [
            'service' => $service,
            'status' => $status,
            'details' => $details
        ];

        self::log(strtoupper(str_replace(' ', '_', $event)), $status === 'success' ? 'INFO' : 'WARNING', $context, $userId);
    }

    /**
     * Get current user ID from session
     */
    private static function getCurrentUserId(): ?int
    {
        if (isset($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        if (isset($_SESSION['sndra_admin']['id'])) {
            return (int) $_SESSION['sndra_admin']['id'];
        }

        return null;
    }

    /**
     * Get client IP address
     */
    private static function getClientIp(): string
    {
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
            return Database::connection();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Accept both the new logger signature and the older
     * log(event, category, level, message, context) style used across the codebase.
     */
    private static function normalizeLogArguments($arg2, $arg3, $arg4, $arg5): array
    {
        if (is_array($arg3) || $arg3 === null) {
            $level = is_string($arg2) ? $arg2 : 'INFO';
            $context = is_array($arg3) ? $arg3 : [];
            $userId = is_int($arg4) ? $arg4 : null;
            $ipAddress = is_string($arg5) ? $arg5 : null;

            return [$level, $context, $userId, $ipAddress];
        }

        $context = is_array($arg5) ? $arg5 : [];
        if (is_string($arg2) && $arg2 !== '') {
            $context['category'] = $arg2;
        }
        if (is_string($arg4) && $arg4 !== '') {
            $context['message'] = $arg4;
        }

        return [self::normalizeLevel((string) $arg3), $context, null, null];
    }

    private static function normalizeLevel(string $level): string
    {
        $normalized = strtoupper(trim($level));

        if ($normalized === 'SUCCESS') {
            return 'INFO';
        }

        if (!isset(self::LOG_LEVELS[$normalized])) {
            return 'INFO';
        }

        return $normalized;
    }

    private static function ensureSchema($connection): void
    {
        if (!$connection instanceof mysqli || !@$connection->ping()) {
            throw new RuntimeException('Audit log database connection is unavailable.');
        }

        $connection->query("
            CREATE TABLE IF NOT EXISTS " . self::TABLE_NAME . " (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                admin_id INT NULL,
                action VARCHAR(100) NOT NULL,
                resource_type VARCHAR(50) NOT NULL DEFAULT 'system',
                resource_id VARCHAR(100) NULL,
                old_values LONGTEXT NULL,
                new_values LONGTEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                session_id VARCHAR(128) NULL,
                status ENUM('success', 'failure', 'warning') NOT NULL DEFAULT 'success',
                details TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_action (action),
                INDEX idx_audit_status (status),
                INDEX idx_audit_created_at (created_at)
            )
        ");
    }

    /**
     * Clean up old audit logs (keep last 90 days)
     */
    private static function cleanupOldLogs($connection): void
    {
        $connection->query("
            DELETE FROM " . self::TABLE_NAME . "
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");
    }

    /**
     * Get available event types
     */
    public static function getEventTypes(): array
    {
        return self::EVENT_TYPES;
    }

    /**
     * Get available log levels
     */
    public static function getLogLevels(): array
    {
        return array_keys(self::LOG_LEVELS);
    }
}
