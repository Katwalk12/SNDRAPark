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
            teller_name,
            COALESCE(teller_details, '') AS teller_details,
            is_active,
            last_login_at,
            created_at
        FROM booth_teller_accounts
        ORDER BY created_at DESC
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
        $tellerName = admin_clean_text(admin_input('teller_name'));
        $tellerDetails = admin_clean_text(admin_input('teller_details'));
        $pin = preg_replace('/\D+/', '', admin_clean_text(admin_input('pin')));

        if ($tellerName === '' || $pin === '') {
            admin_error('Teller name and PIN are required.');
        }

        if (strlen($pin) !== BOOTH_PIN_LENGTH) {
            admin_error('PIN must be exactly ' . BOOTH_PIN_LENGTH . ' digits.');
        }

        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $statement = $connection->prepare("
            INSERT INTO booth_teller_accounts (teller_name, teller_details, pin_code, is_active)
            VALUES (?, ?, ?, 1)
        ");
        $statement->bind_param('sss', $tellerName, $tellerDetails, $pinHash);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_STAFF_CREATED', 'Admin created a booth teller PIN account for ' . $tellerName . '.', [
            'target_type' => 'booth_teller_account',
            'target_id' => (string) $connection->insert_id,
            'status' => 'success',
            'metadata' => [
                'teller_name' => $tellerName,
                'teller_details' => $tellerDetails
            ]
        ]);

        admin_success('Booth teller PIN account created successfully.', admin_staff_payload($connection));
    }

    if ($action === 'update') {
        $staffId = (int) admin_input('staff_id');
        $tellerName = admin_clean_text(admin_input('teller_name'));
        $tellerDetails = admin_clean_text(admin_input('teller_details'));
        $pin = preg_replace('/\D+/', '', admin_clean_text(admin_input('pin')));
        $isActive = admin_bool(admin_input('is_active'));

        if ($staffId <= 0 || $tellerName === '') {
            admin_error('Complete teller details are required.');
        }

        if ($pin !== '' && strlen($pin) !== BOOTH_PIN_LENGTH) {
            admin_error('PIN must be exactly ' . BOOTH_PIN_LENGTH . ' digits.');
        }

        if ($pin !== '') {
            $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            $statement = $connection->prepare("
                UPDATE booth_teller_accounts
                SET teller_name = ?, teller_details = ?, is_active = ?, pin_code = ?
                WHERE id = ?
            ");
            $statement->bind_param('ssisi', $tellerName, $tellerDetails, $isActive, $pinHash, $staffId);
        } else {
            $statement = $connection->prepare("
                UPDATE booth_teller_accounts
                SET teller_name = ?, teller_details = ?, is_active = ?
                WHERE id = ?
            ");
            $statement->bind_param('ssii', $tellerName, $tellerDetails, $isActive, $staffId);
        }

        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_STAFF_UPDATED', 'Admin updated booth teller PIN account #' . $staffId . ' (' . $tellerName . ').', [
            'target_type' => 'booth_teller_account',
            'target_id' => (string) $staffId,
            'status' => 'success',
            'metadata' => [
                'teller_name' => $tellerName,
                'teller_details' => $tellerDetails,
                'is_active' => $isActive,
                'pin_changed' => $pin !== ''
            ]
        ]);
        admin_success('Booth teller PIN account updated successfully.', admin_staff_payload($connection));
    }

    if ($action === 'delete') {
        $staffId = (int) admin_input('staff_id');

        if ($staffId <= 0) {
            admin_error('Invalid staff account selected.');
        }

        $statement = $connection->prepare("DELETE FROM booth_teller_accounts WHERE id = ?");
        $statement->bind_param('i', $staffId);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_STAFF_DELETED', 'Admin deleted booth teller PIN account #' . $staffId . '.', [
            'target_type' => 'booth_teller_account',
            'target_id' => (string) $staffId,
            'status' => 'success'
        ]);

        admin_success('Booth teller PIN account deleted successfully.', admin_staff_payload($connection));
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
