<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/reservation-security.php';

// SECURITY: Enforce admin authentication
$admin = admin_require_auth('admin');

function admin_users_payload(mysqli $connection): array
{
    $users = [];
    $result = $connection->query("
        SELECT
            u.id,
            u.full_name,
            u.email,
            u.birth_date,
            COALESCE(u.status, 'Active') AS status,
            COALESCE(u.warning_count, 0) AS warning_count,
            u.first_warning_at,
            COALESCE(u.account_status, 'active') AS account_status,
            u.account_locked_until,
            u.created_at,
            COUNT(r.id) AS reservation_count,
            (
                SELECT COUNT(*)
                FROM user_violations uv
                WHERE uv.user_id = u.id
            ) AS violation_count,
            (
                SELECT uv.violation_type
                FROM user_violations uv
                WHERE uv.user_id = u.id
                ORDER BY uv.created_at DESC, uv.id DESC
                LIMIT 1
            ) AS latest_violation_type,
            (
                SELECT uv.created_at
                FROM user_violations uv
                WHERE uv.user_id = u.id
                ORDER BY uv.created_at DESC, uv.id DESC
                LIMIT 1
            ) AS latest_violation_at
        FROM users u
        LEFT JOIN reservations r ON r.user_id = u.id
        GROUP BY u.id, u.full_name, u.email, u.birth_date, u.status, u.warning_count, u.first_warning_at, u.account_status, u.account_locked_until, u.created_at
        ORDER BY u.created_at DESC
    ");

    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    return ['users' => $users];
}

try {
    $connection = admin_db();
    reservation_security_expire_due_reservations($connection);

    if (admin_method() === 'GET') {
        admin_success('Users loaded successfully.', admin_users_payload($connection));
    }

    admin_require_method('POST');
    admin_require_csrf();

    $action = admin_clean_text(admin_input('action'));
    $userId = (int) admin_input('user_id');

    if ($action === 'update') {
        $fullName = admin_clean_text(admin_input('full_name'));
        $email = admin_clean_text(admin_input('email'));
        $birthDate = admin_clean_text(admin_input('birth_date')) ?: null;
        $status = admin_clean_text(admin_input('status'));

        if ($userId <= 0 || $fullName === '' || $email === '') {
            admin_error('Complete user details are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            admin_error('Enter a valid email address.');
        }

        if ($birthDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            admin_error('Enter a valid birth date.');
        }

        $allowedStatuses = ['Active', 'Disabled'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'Active';
        }

        $duplicateStatement = $connection->prepare("
            SELECT id
            FROM users
            WHERE email = ?
              AND id <> ?
            LIMIT 1
        ");
        $duplicateStatement->bind_param('si', $email, $userId);
        $duplicateStatement->execute();

        if ($duplicateStatement->get_result()->fetch_assoc()) {
            admin_error('Email already exists.');
        }

        $nameParts = preg_split('/\s+/', $fullName, 2);
        $firstName = trim((string) ($nameParts[0] ?? ''));
        $lastName = trim((string) ($nameParts[1] ?? ''));

        $statement = $connection->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, full_name = ?, email = ?, birth_date = ?, status = ?
            WHERE id = ?
        ");
        $statement->bind_param('ssssssi', $firstName, $lastName, $fullName, $email, $birthDate, $status, $userId);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_USER_UPDATED', 'Admin updated user account #' . $userId . ' (' . $fullName . ').', [
            'target_type' => 'user',
            'target_id' => (string) $userId,
            'status' => 'success',
            'metadata' => [
                'full_name' => $fullName,
                'email' => $email,
                'birth_date' => $birthDate,
                'status' => $status
            ]
        ]);

        admin_success('User updated successfully.', admin_users_payload($connection));
    }

    if ($action === 'disable') {
        $statement = $connection->prepare("UPDATE users SET status = 'Disabled' WHERE id = ?");
        $statement->bind_param('i', $userId);
        $statement->execute();
        reservation_security_create_notification(
            $connection,
            'Account Disabled',
            'Your account has been disabled by the administrator. Please contact support for assistance.',
            'Users',
            $userId,
            null
        );
        admin_audit_log($connection, $admin, 'ADMIN_USER_DISABLED', 'Admin disabled user account #' . $userId . '.', [
            'target_type' => 'user',
            'target_id' => (string) $userId,
            'status' => 'success'
        ]);

        admin_success('User disabled successfully.', admin_users_payload($connection));
    }

    if ($action === 'activate') {
        $statement = $connection->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
        $statement->bind_param('i', $userId);
        $statement->execute();
        reservation_security_create_notification(
            $connection,
            'Account Activated',
            'Your account has been reactivated by the administrator. You may now continue using the system.',
            'Users',
            $userId,
            null
        );
        admin_audit_log($connection, $admin, 'ADMIN_USER_ACTIVATED', 'Admin activated user account #' . $userId . '.', [
            'target_type' => 'user',
            'target_id' => (string) $userId,
            'status' => 'success'
        ]);

        admin_success('User activated successfully.', admin_users_payload($connection));
    }

    if ($action === 'unlock') {
        reservation_security_unlock_user_account(
            $connection,
            $userId,
            true,
            'admin',
            'admin_unlock',
            'Admin manually unlocked the account after review and verification.'
        );
        admin_audit_log($connection, $admin, 'ADMIN_USER_UNLOCKED', 'Admin unlocked user account #' . $userId . '.', [
            'target_type' => 'user',
            'target_id' => (string) $userId,
            'status' => 'success'
        ]);
        admin_success('User account unlocked successfully.', admin_users_payload($connection));
    }

    if ($action === 'delete') {
        $checkStatement = $connection->prepare("SELECT COUNT(*) AS total FROM reservations WHERE user_id = ?");
        $checkStatement->bind_param('i', $userId);
        $checkStatement->execute();
        $totalReservations = (int) (($checkStatement->get_result()->fetch_assoc()['total'] ?? 0));

        if ($totalReservations > 0) {
            admin_error('This user already has reservation records and cannot be deleted. Disable the account instead.');
        }

        $deleteStatement = $connection->prepare("DELETE FROM users WHERE id = ?");
        $deleteStatement->bind_param('i', $userId);
        $deleteStatement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_USER_DELETED', 'Admin deleted user account #' . $userId . '.', [
            'target_type' => 'user',
            'target_id' => (string) $userId,
            'status' => 'success'
        ]);

        admin_success('User deleted successfully.', admin_users_payload($connection));
    }

    admin_error('Invalid user action.');
} catch (Throwable $exception) {
    admin_log('get-users-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load or manage users.', 500, [
        'details' => $exception->getMessage()
    ]);
}
