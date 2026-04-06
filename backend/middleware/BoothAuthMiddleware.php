<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditLogger.php';

class BoothAuthMiddleware
{
    private const BOOTH_TOKEN_HEADER = 'X-Booth-Token';
    private const BOOTH_API_KEY_HEADER = 'X-Booth-API-Key';

    /**
     * Authenticate booth request
     *
     * @param string $requiredPermission Permission required for this action
     * @return array Booth staff user data
     * @throws RuntimeException If authentication fails
     */
    public static function authenticate(?string $requiredPermission = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionUser = self::authenticateBySession($requiredPermission);
        if ($sessionUser !== null) {
            return $sessionUser;
        }

        // Check for booth API key first (for system-to-system calls)
        $apiKey = self::getApiKeyFromRequest();
        if ($apiKey) {
            return self::authenticateByApiKey($apiKey, $requiredPermission);
        }

        // Check for booth token (for authenticated booth staff)
        $token = self::getTokenFromRequest();
        if ($token) {
            return self::authenticateByToken($token, $requiredPermission);
        }

        // No authentication provided
        AuditLogger::log('booth_auth_missing', 'booth_auth', 'warning',
            'Booth endpoint accessed without authentication');
        throw new RuntimeException('Booth authentication required.', 401);
    }

    private static function authenticateBySession(?string $requiredPermission = null): ?array
    {
        $session = $_SESSION['sndra_admin'] ?? null;

        if (!is_array($session) || (string) ($session['role'] ?? '') !== 'booth') {
            return null;
        }

        $staffId = (int) ($session['id'] ?? 0);
        if ($staffId <= 0) {
            throw new RuntimeException('Invalid booth session.', 401);
        }

        try {
            $connection = Database::connection();
            $stmt = $connection->prepare("
                SELECT id, email, full_name, role, is_active
                FROM staff_accounts
                WHERE id = ? AND role = 'booth'
                LIMIT 1
            ");
            $stmt->bind_param('i', $staffId);
            $stmt->execute();
            $result = $stmt->get_result();
            $staff = $result->fetch_assoc();
            $stmt->close();

            if (!$staff || (int) ($staff['is_active'] ?? 0) !== 1) {
                throw new RuntimeException('Invalid booth session.', 401);
            }

            if ($requiredPermission && !self::hasBoothPermission((string) $staff['role'], $requiredPermission)) {
                throw new RuntimeException('Insufficient booth permissions.', 403);
            }

            return [
                'id' => (int) $staff['id'],
                'email' => (string) ($staff['email'] ?? ''),
                'full_name' => (string) ($staff['full_name'] ?? 'Booth Teller'),
                'role' => (string) ($staff['role'] ?? 'booth'),
                'booth_location' => null
            ];
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new RuntimeException('Booth authentication failed.', 500);
        }
    }

    /**
     * Authenticate using API key (for automated systems)
     */
    private static function authenticateByApiKey(string $apiKey, ?string $requiredPermission = null): array
    {
        try {
            $connection = Database::connection();

            // Check if API key exists and is active
            $stmt = $connection->prepare("
                SELECT u.id, u.email, u.full_name, u.role, bs.is_active, bs.booth_location
                FROM users u
                INNER JOIN booth_staff bs ON u.id = bs.user_id
                WHERE bs.api_key = ? AND bs.is_active = TRUE
            ");

            $stmt->bind_param('s', $apiKey);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                AuditLogger::log('booth_api_key_invalid', 'booth_auth', 'warning',
                    'Invalid booth API key used');
                throw new RuntimeException('Invalid booth credentials.', 401);
            }

            // Check permission if required
            if ($requiredPermission && !self::hasBoothPermission($user['role'], $requiredPermission)) {
                AuditLogger::log('booth_permission_denied', 'booth_auth', 'warning',
                    "Booth user {$user['id']} attempted {$requiredPermission}");
                throw new RuntimeException('Insufficient booth permissions.', 403);
            }

            AuditLogger::log('booth_api_access', 'booth_system', 'success',
                "Booth API access granted for {$user['email']}", ['user_id' => $user['id']]);

            return $user;
        } catch (Exception $e) {
            AuditLogger::log('booth_api_key_error', 'booth_auth', 'error',
                'Database error during booth API key authentication: ' . $e->getMessage());
            throw new RuntimeException('Booth authentication failed.', 500);
        }
    }

    /**
     * Authenticate using session token (for booth staff logged in)
     */
    private static function authenticateByToken(string $token, ?string $requiredPermission = null): array
    {
        try {
            $connection = Database::connection();

            // Validate token and get user
            $stmt = $connection->prepare("
                SELECT u.id, u.email, u.full_name, u.role, bs.is_active, bs.booth_location
                FROM users u
                INNER JOIN booth_staff bs ON u.id = bs.user_id
                WHERE bs.session_token = ? AND bs.is_active = TRUE
                AND bs.token_expires > NOW()
            ");

            $stmt->bind_param('s', $token);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                AuditLogger::log('booth_token_invalid', 'booth_auth', 'warning',
                    'Invalid or expired booth session token used');
                throw new RuntimeException('Invalid booth session.', 401);
            }

            // Check permission if required
            if ($requiredPermission && !self::hasBoothPermission($user['role'], $requiredPermission)) {
                AuditLogger::log('booth_permission_denied', 'booth_auth', 'warning',
                    "Booth user {$user['id']} attempted {$requiredPermission}");
                throw new RuntimeException('Insufficient booth permissions.', 403);
            }

            AuditLogger::log('booth_session_access', 'booth_staff', 'success',
                "Booth session access granted for {$user['email']}", ['user_id' => $user['id']]);

            return $user;
        } catch (Exception $e) {
            AuditLogger::log('booth_token_error', 'booth_auth', 'error',
                'Database error during booth token authentication: ' . $e->getMessage());
            throw new RuntimeException('Booth authentication failed.', 500);
        }
    }

    /**
     * Generate API key for booth staff
     */
    public static function generateApiKey(int $userId): string
    {
        $apiKey = bin2hex(random_bytes(32)); // 64 character hex string

        try {
            $connection = Database::connection();

            // Check if booth_staff record exists
            $stmt = $connection->prepare("SELECT id FROM booth_staff WHERE user_id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result->fetch_assoc();
            $stmt->close();

            if ($exists) {
                // Update existing record
                $stmt = $connection->prepare("
                    UPDATE booth_staff
                    SET api_key = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->bind_param('si', $apiKey, $userId);
            } else {
                // Create new record
                $stmt = $connection->prepare("
                    INSERT INTO booth_staff (user_id, api_key, is_active, created_at, updated_at)
                    VALUES (?, ?, TRUE, NOW(), NOW())
                ");
                $stmt->bind_param('is', $userId, $apiKey);
            }

            $stmt->execute();
            $stmt->close();

            AuditLogger::log('generate_api_key', 'admin', 'success',
                'Generated new API key for booth staff', [
                    'admin_id' => $_SESSION['user_id'] ?? null,
                    'target_type' => 'booth_staff',
                    'target_id' => $userId,
                    'api_key' => '***'
                ]);

            return $apiKey;
        } catch (Exception $e) {
            AuditLogger::log('generate_api_key', 'admin', 'error',
                'Failed to generate API key for booth staff: ' . $e->getMessage(), [
                    'admin_id' => $_SESSION['user_id'] ?? null,
                    'target_type' => 'booth_staff',
                    'target_id' => $userId
                ]);
            throw new RuntimeException('Failed to generate API key.', 500);
        }
    }

    /**
     * Create booth session token
     */
    public static function createBoothSession(int $userId, ?string $boothLocation = null): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+8 hours')); // 8 hour booth sessions

        try {
            $connection = Database::connection();

            // Update or insert booth_staff record
            $stmt = $connection->prepare("
                INSERT INTO booth_staff (user_id, session_token, token_expires, booth_location, is_active, updated_at)
                VALUES (?, ?, ?, ?, TRUE, NOW())
                ON DUPLICATE KEY UPDATE
                session_token = VALUES(session_token),
                token_expires = VALUES(token_expires),
                booth_location = VALUES(booth_location),
                is_active = TRUE,
                updated_at = NOW()
            ");

            $stmt->bind_param('isss', $userId, $token, $expires, $boothLocation);
            $stmt->execute();
            $stmt->close();

            AuditLogger::log('booth_session_created', 'auth', 'success',
                "Booth session created for location: {$boothLocation}", ['user_id' => $userId]);

            return $token;
        } catch (Exception $e) {
            AuditLogger::log('booth_session_failed', 'auth', 'error',
                'Failed to create booth session: ' . $e->getMessage(), ['user_id' => $userId]);
            throw new RuntimeException('Failed to create booth session.', 500);
        }
    }

    /**
     * Revoke booth session
     */
    public static function revokeBoothSession(int $userId): void
    {
        try {
            $connection = Database::connection();

            $stmt = $connection->prepare("
                UPDATE booth_staff
                SET session_token = NULL, token_expires = NULL, updated_at = NOW()
                WHERE user_id = ?
            ");

            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();

            AuditLogger::log('booth_session_revoked', 'auth', 'success',
                'Booth session revoked', ['user_id' => $userId]);
        } catch (Exception $e) {
            AuditLogger::log('booth_session_revoke_failed', 'auth', 'error',
                'Failed to revoke booth session: ' . $e->getMessage(), ['user_id' => $userId]);
        }
    }

    /**
     * Check if user has booth permission
     */
    private static function hasBoothPermission(string $role, string $permission): bool
    {
        // Booth staff have specific permissions
        $boothPermissions = [
            'booth' => [
                'scan_barcode',
                'process_payment',
                'view_monitor',
                'update_reservation'
            ],
            'booth_staff' => [
                'scan_barcode',
                'process_payment',
                'view_monitor',
                'update_reservation'
            ]
        ];

        return isset($boothPermissions[$role]) && in_array($permission, $boothPermissions[$role]);
    }

    /**
     * Get API key from request headers
     */
    private static function getApiKeyFromRequest(): ?string
    {
        return $_SERVER[self::BOOTH_API_KEY_HEADER] ??
               $_SERVER['HTTP_' . str_replace('-', '_', self::BOOTH_API_KEY_HEADER)] ??
               null;
    }

    /**
     * Get token from request headers
     */
    private static function getTokenFromRequest(): ?string
    {
        return $_SERVER[self::BOOTH_TOKEN_HEADER] ??
               $_SERVER['HTTP_' . str_replace('-', '_', self::BOOTH_TOKEN_HEADER)] ??
               null;
    }

    /**
     * Get authenticated booth user without throwing exceptions
     */
    public static function getAuthenticatedBoothUser(): ?array
    {
        try {
            return self::authenticate();
        } catch (Exception $e) {
            return null;
        }
    }
}
