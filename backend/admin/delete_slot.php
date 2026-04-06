<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/system-logs.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

try {
    admin_require_method('POST');

    // SECURITY: Validate CSRF token
    admin_require_csrf();

    $payload = admin_request_data();
    $slotId = (int) ($payload['slot_id'] ?? 0);

    if ($slotId <= 0) {
        admin_error('A valid slot_id is required.', 422);
    }

    $connection = admin_db();
    parking_sync_slot_statuses($connection);

    $lookupStatement = $connection->prepare("
        SELECT
            id,
            floor_name,
            slot_code,
            status
        FROM parking_slots
        WHERE id = ?
        LIMIT 1
    ");
    $lookupStatement->bind_param('i', $slotId);
    $lookupStatement->execute();
    $slot = $lookupStatement->get_result()->fetch_assoc();

    if (!$slot) {
        admin_error('Parking slot not found.', 404);
    }

    $liveStatus = (string) ($slot['status'] ?? 'Available');
    if (in_array($liveStatus, ['Reserved', 'Occupied'], true)) {
        admin_error('Reserved or occupied slots cannot be deleted.', 409, [
            'slot_id' => $slotId,
            'status' => $liveStatus
        ]);
    }

    $deleteStatement = $connection->prepare("
        DELETE FROM parking_slots
        WHERE id = ?
        LIMIT 1
    ");
    $deleteStatement->bind_param('i', $slotId);
    $deleteStatement->execute();

    system_logs_write($connection, [
        'actor_role' => 'admin',
        'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
        'action_type' => 'ADMIN_SLOT_DELETED',
        'description' => 'Admin deleted slot ' . (string) ($slot['slot_code'] ?? 'Unknown Slot') . ' on ' . (string) ($slot['floor_name'] ?? 'Unknown Floor') . '.',
        'related_floor' => (string) ($slot['floor_name'] ?? ''),
        'related_slot' => (string) ($slot['slot_code'] ?? ''),
        'status' => 'Deleted'
    ]);
    admin_audit_log($connection, $admin, 'ADMIN_SLOT_DELETED', 'Admin deleted slot ' . (string) ($slot['slot_code'] ?? 'Unknown Slot') . ' on ' . (string) ($slot['floor_name'] ?? 'Unknown Floor') . '.', [
        'target_type' => 'parking_slot',
        'target_id' => (string) $slotId,
        'status' => 'success',
        'metadata' => [
            'floor_name' => $slot['floor_name'] ?? '',
            'slot_code' => $slot['slot_code'] ?? '',
            'live_status' => $liveStatus
        ]
    ]);

    admin_success('Slot deleted successfully', [
        'slot_id' => $slotId,
        'floor_name' => $slot['floor_name'],
        'slot_code' => $slot['slot_code']
    ]);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    admin_log('delete-slot-failed', [
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    admin_error(
        $exception->getMessage() ?: 'Failed to delete slot.',
        $status,
        $status >= 500 ? ['details' => $exception->getMessage()] : []
    );
}
