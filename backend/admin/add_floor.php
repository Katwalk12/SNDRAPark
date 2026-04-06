<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/system-logs.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    booth_error('Method not allowed.', 405);
}

// SECURITY: Validate CSRF token
admin_require_csrf();

try {
    $payload = parking_request_data();
    $connection = booth_db();
    $floor = parking_add_floor(
        $connection,
        (string) ($payload['floor_name'] ?? ''),
        (string) ($payload['floor_label'] ?? '')
    );

    system_logs_write($connection, [
        'actor_role' => 'admin',
        'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
        'action_type' => 'ADMIN_FLOOR_CREATED',
        'description' => 'Admin added parking floor ' . (string) ($floor['floor_label'] ?? $floor['floor_name'] ?? 'Unknown Floor') . '.',
        'related_floor' => (string) ($floor['floor_name'] ?? ''),
        'status' => 'Created'
    ]);
    admin_audit_log($connection, $admin, 'ADMIN_FLOOR_CREATED', 'Admin added parking floor ' . (string) ($floor['floor_label'] ?? $floor['floor_name'] ?? 'Unknown Floor') . '.', [
        'target_type' => 'parking_floor',
        'target_id' => (string) ($floor['id'] ?? ''),
        'status' => 'success',
        'metadata' => [
            'floor_name' => $floor['floor_name'] ?? '',
            'floor_label' => $floor['floor_label'] ?? ''
        ]
    ]);

    booth_success('Parking floor added successfully.', [
        'floor' => $floor,
        'floors' => parking_get_floors($connection, false)
    ], 201);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();

    if ((int) ($exception->getCode() ?? 0) === 1062) {
        $status = 409;
    }

    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    booth_log('admin-add-floor-failed', [
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    booth_error(
        $status === 409 ? 'This floor already exists.' : $exception->getMessage(),
        $status,
        $status >= 500 ? ['details' => $exception->getMessage()] : []
    );
}
