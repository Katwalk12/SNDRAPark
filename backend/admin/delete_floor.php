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
    $floorId = (int) ($payload['floor_id'] ?? 0);

    if ($floorId <= 0) {
        admin_error('A valid floor_id is required.', 422);
    }

    $connection = admin_db();
    parking_sync_slot_statuses($connection);

    $floorStatement = $connection->prepare("
        SELECT
            id,
            floor_name,
            floor_label
        FROM parking_floors
        WHERE id = ?
        LIMIT 1
    ");
    $floorStatement->bind_param('i', $floorId);
    $floorStatement->execute();
    $floor = $floorStatement->get_result()->fetch_assoc();

    if (!$floor) {
        admin_error('Parking floor not found.', 404);
    }

    $activeSlotStatement = $connection->prepare("
        SELECT COUNT(*) AS total_active_slots
        FROM parking_slots
        WHERE floor_id = ?
          AND status IN ('Reserved', 'Occupied')
    ");
    $activeSlotStatement->bind_param('i', $floorId);
    $activeSlotStatement->execute();
    $activeSlotRow = $activeSlotStatement->get_result()->fetch_assoc() ?: [];
    $totalActiveSlots = (int) ($activeSlotRow['total_active_slots'] ?? 0);

    if ($totalActiveSlots > 0) {
        admin_error('Reserved or occupied slots must be cleared before deleting this floor.', 409, [
            'floor_id' => $floorId,
            'floor_name' => $floor['floor_name'],
            'active_slots' => $totalActiveSlots
        ]);
    }

    $connection->begin_transaction();

    try {
        $deleteSlotsStatement = $connection->prepare("
            DELETE FROM parking_slots
            WHERE floor_id = ?
        ");
        $deleteSlotsStatement->bind_param('i', $floorId);
        $deleteSlotsStatement->execute();
        $deletedSlots = $deleteSlotsStatement->affected_rows;

        $deleteFloorStatement = $connection->prepare("
            DELETE FROM parking_floors
            WHERE id = ?
            LIMIT 1
        ");
        $deleteFloorStatement->bind_param('i', $floorId);
        $deleteFloorStatement->execute();

        $connection->commit();

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_FLOOR_DELETED',
            'description' => 'Admin deleted floor ' . (string) ($floor['floor_label'] ?? $floor['floor_name'] ?? 'Unknown Floor') . '.',
            'related_floor' => (string) ($floor['floor_name'] ?? ''),
            'status' => 'Deleted'
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_FLOOR_DELETED', 'Admin deleted floor ' . (string) ($floor['floor_label'] ?? $floor['floor_name'] ?? 'Unknown Floor') . '.', [
            'target_type' => 'parking_floor',
            'target_id' => (string) $floorId,
            'status' => 'success',
            'metadata' => [
                'floor_name' => $floor['floor_name'] ?? '',
                'floor_label' => $floor['floor_label'] ?? '',
                'deleted_slots' => $deletedSlots
            ]
        ]);

        admin_success('Floor deleted successfully.', [
            'floor_id' => $floorId,
            'floor_name' => $floor['floor_name'],
            'floor_label' => $floor['floor_label'],
            'deleted_slots' => $deletedSlots
        ]);
    } catch (Throwable $transactionException) {
        $connection->rollback();
        throw $transactionException;
    }
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    admin_log('delete-floor-failed', [
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    admin_error(
        $exception->getMessage() ?: 'Failed to delete floor.',
        $status,
        $status >= 500 ? ['details' => $exception->getMessage()] : []
    );
}
