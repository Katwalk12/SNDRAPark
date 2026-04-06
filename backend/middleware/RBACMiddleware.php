<?php

require_once __DIR__ . '/AuditLogger.php';

class RBACMiddleware
{
    // Define roles and their permissions
    private const PERMISSIONS = [
        'user' => [
            'auth.login',
            'auth.logout',
            'auth.register',
            'auth.session',
            'users.profile',
            'users.update',
            'reservations.create',
            'reservations.read',
            'reservations.cancel',
            'notifications.read',
            'feedback.submit'
        ],
        'admin' => [
            'auth.login',
            'auth.logout',
            'auth.session',
            'admin.dashboard',
            'admin.users.read',
            'admin.users.update',
            'admin.users.delete',
            'admin.reservations.read',
            'admin.reservations.update',
            'admin.reservations.delete',
            'admin.slots.manage',
            'admin.floors.manage',
            'admin.staff.manage',
            'admin.notifications.manage',
            'admin.settings.manage',
            'admin.payments.read',
            'admin.logs.read',
            'admin.feedback.read',
            'audit.read'
        ],
        'booth_staff' => [
            'auth.login',
            'auth.logout',
            'auth.session',
            'booth.scan',
            'booth.pay',
            'booth.monitor',
            'booth.realtime',
            'booth.recent',
            'reservations.update', // For marking as parked/exited
            'payments.update' // For processing payments
        ]
    ];

    /**
     * Check if current user has permission for an action
     *
     * @param string $permission Permission string (e.g., 'admin.users.read')
     * @param int|null $userId User ID to check (defaults to current session user)
     * @return bool True if user has permission
     * @throws RuntimeException If user is not authenticated or lacks permission
     */
    public static function authorize(string $permission, ?int $userId = null): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Get user ID from session if not provided
        if ($userId === null) {
            $userId = $_SESSION['user_id'] ?? null;
        }

        if (!$userId) {
            AuditLogger::log('UNAUTHORIZED_ACCESS', 'WARNING', [
                'message' => "Attempted to access {$permission} without authentication",
                'category' => 'rbac',
                'permission' => $permission
            ]);
            throw new RuntimeException('Authentication required.', 401);
        }

        // Get user role
        $role = self::getUserRole($userId);
        if (!$role) {
            AuditLogger::log('invalid_role', 'rbac', 'failure',
                "User {$userId} has invalid or missing role");
            throw new RuntimeException('Invalid user role.', 403);
        }

        // Check if role has the required permission
        if (!self::hasPermission($role, $permission)) {
            AuditLogger::log('insufficient_permissions', 'rbac', 'failure',
                "User {$userId} (role: {$role}) attempted to access {$permission}");
            throw new RuntimeException('Insufficient permissions.', 403);
        }

        return true;
    }

    /**
     * Check if a role has a specific permission (without throwing exceptions)
     *
     * @param string $role User role
     * @param string $permission Permission to check
     * @return bool True if role has permission
     */
    public static function hasPermission(string $role, string $permission): bool
    {
        return isset(self::PERMISSIONS[$role]) && in_array($permission, self::PERMISSIONS[$role]);
    }

    /**
     * Get all permissions for a role
     *
     * @param string $role User role
     * @return array Array of permissions
     */
    public static function getRolePermissions(string $role): array
    {
        return self::PERMISSIONS[$role] ?? [];
    }

    /**
     * Get user role from database
     *
     * @param int $userId User ID
     * @return string|null User role or null if not found
     */
    public static function getUserRole(int $userId): ?string
    {
        try {
            $connection = Database::connection();
            $stmt = $connection->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return $row['role'] ?? null;
        } catch (Exception $e) {
            error_log("RBACMiddleware::getUserRole failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Set user role (admin only operation)
     *
     * @param int $userId User ID to update
     * @param string $newRole New role to assign
     * @param int $adminId Admin performing the action
     * @return bool True if role updated successfully
     */
    public static function setUserRole(int $userId, string $newRole, int $adminId): bool
    {
        // Validate role exists
        if (!isset(self::PERMISSIONS[$newRole])) {
            throw new InvalidArgumentException("Invalid role: {$newRole}");
        }

        try {
            $connection = Database::connection();

            // Get current role for audit logging
            $currentRole = self::getUserRole($userId);

            // Update role
            $stmt = $connection->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->bind_param('si', $newRole, $userId);
            $result = $stmt->execute();
            $stmt->close();

            if ($result) {
                // Log the role change
                AuditLogger::log('role_change', 'admin', 'success',
                    "Changed user role from '{$currentRole}' to '{$newRole}'", [
                        'admin_id' => $adminId,
                        'target_type' => 'user',
                        'target_id' => $userId,
                        'old_role' => $currentRole,
                        'new_role' => $newRole
                    ]);
            }

            return $result;
        } catch (Exception $e) {
            error_log("RBACMiddleware::setUserRole failed: " . $e->getMessage());
            AuditLogger::log('role_change', 'admin', 'error',
                'Failed to change user role: ' . $e->getMessage(), [
                    'admin_id' => $adminId,
                    'target_type' => 'user',
                    'target_id' => $userId,
                    'new_role' => $newRole
                ]);
            return false;
        }
    }

    /**
     * Check if current user is admin
     *
     * @return bool True if user is admin
     */
    public static function isAdmin(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return false;
        }

        $role = self::getUserRole($userId);
        return $role === 'admin';
    }

    /**
     * Check if current user is booth staff
     *
     * @return bool True if user is booth staff
     */
    public static function isBoothStaff(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return false;
        }

        $role = self::getUserRole($userId);
        return $role === 'booth_staff';
    }

    /**
     * Check if current user is regular user
     *
     * @return bool True if user is regular user
     */
    public static function isUser(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return false;
        }

        $role = self::getUserRole($userId);
        return $role === 'user';
    }

    /**
     * Authorize admin-only actions
     *
     * @throws RuntimeException If user is not admin
     */
    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            AuditLogger::log('admin_access_denied', 'rbac', 'failure',
                'Non-admin user attempted to access admin-only resource');
            throw new RuntimeException('Admin access required.', 403);
        }
    }

    /**
     * Authorize booth staff actions
     *
     * @throws RuntimeException If user is not booth staff
     */
    public static function requireBoothStaff(): void
    {
        if (!self::isBoothStaff()) {
            AuditLogger::log('booth_access_denied', 'rbac', 'failure',
                'Non-booth-staff user attempted to access booth resource');
            throw new RuntimeException('Booth staff access required.', 403);
        }
    }

    /**
     * Get current user permissions
     *
     * @return array Array of permission strings
     */
    public static function getCurrentUserPermissions(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return [];
        }

        $role = self::getUserRole($userId);
        return self::getRolePermissions($role);
    }

    /**
     * Validate that a user can access a specific resource
     *
     * @param string $resourceType Type of resource (user, reservation, etc.)
     * @param int $resourceId Resource ID
     * @param string $action Action to perform (read, update, delete)
     * @return bool True if access allowed
     */
    public static function canAccessResource(string $resourceType, int $resourceId, string $action): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return false;
        }

        $role = self::getUserRole($userId);
        $permission = "{$resourceType}.{$action}";

        // Check general permission
        if (!self::hasPermission($role, $permission)) {
            return false;
        }

        // For user-specific resources, check ownership
        if ($resourceType === 'user' && $resourceId !== $userId && $role !== 'admin') {
            return false;
        }

        // For reservations, check ownership unless admin
        if ($resourceType === 'reservation' && $role !== 'admin' && $role !== 'booth_staff') {
            try {
                $connection = Database::connection();
                $stmt = $connection->prepare("SELECT user_id FROM reservations WHERE id = ?");
                $stmt->bind_param('i', $resourceId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();

                if (!$row || (int)$row['user_id'] !== $userId) {
                    return false;
                }
            } catch (Exception $e) {
                error_log("RBACMiddleware::canAccessResource failed: " . $e->getMessage());
                return false;
            }
        }

        return true;
    }
}
