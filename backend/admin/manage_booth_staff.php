<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');

function admin_staff_payload(mysqli $connection): array
{
    $staff = [];
    $result = $connection->query("
        SELECT
            id,
            full_name,
            COALESCE(username, '') AS username,
            email,
            role,
            is_active,
            last_login_at,
            created_at
        FROM staff_accounts
        ORDER BY role ASC, created_at DESC
    ");

    while ($row = $result->fetch_assoc()) {
        $staff[] = $row;
    }

    return ['staff' => $staff];
}

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('Booth staff loaded successfully.', admin_staff_payload($connection));
    }

    admin_require_method('POST');
    admin_require_csrf();

    $action = admin_clean_text(admin_input('action'));

    if ($action === 'create') {
        $fullName = admin_clean_text(admin_input('full_name'));
        $username = admin_clean_text(admin_input('username'));
        $email = admin_clean_text(admin_input('email'));
        $password = admin_clean_text(admin_input('password'));
        $role = admin_clean_text(admin_input('role')) ?: 'booth';

        if ($fullName === '' || $email === '' || $password === '') {
            admin_error('Name, email, and password are required.');
        }

        if (!in_array($role, ['admin', 'booth'], true)) {
            $role = 'booth';
        }

        if ($username === '') {
            $username = strtok($email, '@') ?: $email;
        }

        $passwordHash = admin_staff_password_hash($password);
        $statement = $connection->prepare("
            INSERT INTO staff_accounts (full_name, username, email, password_hash, role, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $statement->bind_param('sssss', $fullName, $username, $email, $passwordHash, $role);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_STAFF_CREATED', 'Admin created a new ' . $role . ' staff account for ' . $fullName . '.', [
            'target_type' => 'staff_account',
            'target_id' => (string) $connection->insert_id,
            'status' => 'success',
            'metadata' => [
                'full_name' => $fullName,
                'username' => $username,
                'email' => $email,
                'role' => $role
            ]
        ]);

        admin_success('Staff account created successfully.', admin_staff_payload($connection));
    }

    if ($action === 'update') {
        $staffId = (int) admin_input('staff_id');
        $fullName = admin_clean_text(admin_input('full_name'));
        $username = admin_clean_text(admin_input('username'));
        $email = admin_clean_text(admin_input('email'));
        $role = admin_clean_text(admin_input('role')) ?: 'booth';
        $password = admin_clean_text(admin_input('password'));
        $isActive = admin_bool(admin_input('is_active'));

        if ($staffId <= 0 || $fullName === '' || $email === '') {
            admin_error('Complete staff details are required.');
        }

        if (!in_array($role, ['admin', 'booth'], true)) {
            $role = 'booth';
        }

        if ($password !== '') {
            $passwordHash = admin_staff_password_hash($password);
            $statement = $connection->prepare("
                UPDATE staff_accounts
                SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?, password_hash = ?
                WHERE id = ?
            ");
            $statement->bind_param('ssssisi', $fullName, $username, $email, $role, $isActive, $passwordHash, $staffId);
        } else {
            $statement = $connection->prepare("
                UPDATE staff_accounts
                SET full_name = ?, username = ?, email = ?, role = ?, is_active = ?
                WHERE id = ?
            ");
            $statement->bind_param('ssssii', $fullName, $username, $email, $role, $isActive, $staffId);
        }

        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_STAFF_UPDATED', 'Admin updated staff account #' . $staffId . ' (' . $fullName . ').', [
            'target_type' => 'staff_account',
            'target_id' => (string) $staffId,
            'status' => 'success',
            'metadata' => [
                'full_name' => $fullName,
                'username' => $username,
                'email' => $email,
                'role' => $role,
                'is_active' => $isActive,
                'password_changed' => $password !== ''
            ]
        ]);
        admin_success('Staff account updated successfully.', admin_staff_payload($connection));
    }

    if ($action === 'delete') {
        $staffId = (int) admin_input('staff_id');

        if ($staffId <= 0) {
            admin_error('Invalid staff account selected.');
        }

        $statement = $connection->prepare("DELETE FROM staff_accounts WHERE id = ?");
        $statement->bind_param('i', $staffId);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_STAFF_DELETED', 'Admin deleted staff account #' . $staffId . '.', [
            'target_type' => 'staff_account',
            'target_id' => (string) $staffId,
            'status' => 'success'
        ]);

        admin_success('Staff account deleted successfully.', admin_staff_payload($connection));
    }

    admin_error('Invalid staff action.');
} catch (Throwable $exception) {
    admin_log('manage-booth-staff-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to manage booth staff.', 500, [
        'details' => $exception->getMessage()
    ]);
}
