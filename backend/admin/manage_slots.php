<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/system-logs.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

function admin_get_floor_record(mysqli $connection, string $floorName): ?array
{
    $statement = $connection->prepare("
        SELECT id, floor_name, floor_label
        FROM parking_floors
        WHERE floor_name = ?
        LIMIT 1
    ");
    $statement->bind_param('s', $floorName);
    $statement->execute();

    return $statement->get_result()->fetch_assoc() ?: null;
}

function admin_get_floor_record_by_id(mysqli $connection, int $floorId): ?array
{
    $statement = $connection->prepare("
        SELECT id, floor_name, floor_label
        FROM parking_floors
        WHERE id = ?
        LIMIT 1
    ");
    $statement->bind_param('i', $floorId);
    $statement->execute();

    return $statement->get_result()->fetch_assoc() ?: null;
}

function admin_slot_exists(mysqli $connection, int $floorId, string $slotCode, ?int $excludeSlotId = null): bool
{
    $sql = "
        SELECT id
        FROM parking_slots
        WHERE floor_id = ?
          AND slot_code = ?
    ";
    $types = 'is';
    $params = [$floorId, $slotCode];

    if ($excludeSlotId !== null && $excludeSlotId > 0) {
        $sql .= ' AND id <> ? ';
        $types .= 'i';
        $params[] = $excludeSlotId;
    }

    $sql .= ' LIMIT 1 ';
    $statement = $connection->prepare($sql);
    $statement->bind_param($types, ...$params);
    $statement->execute();

    return (bool) $statement->get_result()->fetch_assoc();
}

function admin_manage_slots_payload(mysqli $connection): array
{
    $floors = parking_get_floors($connection, false);
    $slots = array_map(static function (array $slot): array {
        return [
            'id' => $slot['id'],
            'floor_id' => $slot['floor_id'],
            'floor_name' => $slot['floor_name'],
            'slot_code' => $slot['slot_code'],
            'row_label' => $slot['row_label'],
            'is_active' => $slot['is_active'],
            'manual_status' => $slot['manual_status'],
            'live_status' => $slot['status'],
            'status' => $slot['status']
        ];
    }, parking_get_slots($connection, null, null, false));

    return [
        'floors' => $floors,
        'slots' => $slots
    ];
}

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('Slot management data loaded successfully.', admin_manage_slots_payload($connection));
    }

    admin_require_method('POST');
    admin_require_csrf();

    $action = admin_clean_text(admin_input('action'));

    if ($action === 'add_floor') {
        $floor = parking_add_floor(
            $connection,
            admin_clean_text(admin_input('floor_name')),
            admin_clean_text(admin_input('floor_label'))
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

        admin_success('Parking floor added successfully.', admin_manage_slots_payload($connection));
    }

    if ($action === 'update_floor') {
        $floorId = (int) admin_input('floor_id');
        $isActive = admin_bool(admin_input('is_active'));

        if ($floorId <= 0) {
            admin_error('A valid floor is required.', 422);
        }

        $statement = $connection->prepare("UPDATE parking_floors SET is_active = ? WHERE id = ?");
        $statement->bind_param('ii', $isActive, $floorId);
        $statement->execute();

        $floorStatement = $connection->prepare("
            SELECT floor_name, COALESCE(NULLIF(floor_label, ''), floor_name) AS floor_label
            FROM parking_floors
            WHERE id = ?
            LIMIT 1
        ");
        $floorStatement->bind_param('i', $floorId);
        $floorStatement->execute();
        $floor = $floorStatement->get_result()->fetch_assoc() ?: [];

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_FLOOR_UPDATED',
            'description' => 'Admin updated floor ' . (string) ($floor['floor_label'] ?? $floor['floor_name'] ?? 'Unknown Floor') . '.',
            'related_floor' => (string) ($floor['floor_name'] ?? ''),
            'status' => $isActive === 1 ? 'Active' : 'Inactive'
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_FLOOR_UPDATED', 'Admin updated floor ' . (string) ($floor['floor_label'] ?? $floor['floor_name'] ?? 'Unknown Floor') . '.', [
            'target_type' => 'parking_floor',
            'target_id' => (string) $floorId,
            'status' => 'success',
            'metadata' => [
                'floor_name' => $floor['floor_name'] ?? '',
                'floor_label' => $floor['floor_label'] ?? '',
                'is_active' => $isActive
            ]
        ]);

        admin_success('Parking floor updated successfully.', admin_manage_slots_payload($connection));
    }

    if ($action === 'add_slot') {
        $floorId = (int) admin_input('floor_id');
        $floorName = admin_clean_text(admin_input('floor_name'));
        $slotCode = strtoupper(admin_clean_text(admin_input('slot_code')));

        if (($floorId <= 0 && $floorName === '') || $slotCode === '') {
            admin_error('Floor and slot code are required.', 422);
        }

        $floor = $floorId > 0
            ? admin_get_floor_record_by_id($connection, $floorId)
            : admin_get_floor_record($connection, $floorName);

        if (!$floor) {
            admin_error('Selected floor was not found.', 404);
        }

        $floorId = (int) $floor['id'];
        $floorName = (string) ($floor['floor_name'] ?? '');

        if (admin_slot_exists($connection, $floorId, $slotCode)) {
            admin_error('This slot code already exists on the selected floor.', 409);
        }

        $statement = $connection->prepare("
            INSERT INTO parking_slots (floor_id, floor_name, slot_code, row_label, status, is_active, manual_status)
            VALUES (?, ?, ?, ?, 'Available', 1, 'Auto')
        ");
        $rowLabel = parking_row_label_from_slot($slotCode);
        $statement->bind_param('isss', $floorId, $floorName, $slotCode, $rowLabel);
        $statement->execute();
        $slotId = (int) $connection->insert_id;

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_SLOT_CREATED',
            'description' => 'Admin added slot ' . $slotCode . ' on ' . $floorName . '.',
            'related_floor' => $floorName,
            'related_slot' => $slotCode,
            'status' => 'Available'
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_SLOT_CREATED', 'Admin added slot ' . $slotCode . ' on ' . $floorName . '.', [
            'target_type' => 'parking_slot',
            'target_id' => (string) $slotId,
            'status' => 'success',
            'metadata' => [
                'floor_id' => $floorId,
                'floor_name' => $floorName,
                'slot_code' => $slotCode
            ]
        ]);

        admin_success('Parking slot added successfully.', admin_manage_slots_payload($connection));
    }

    if ($action === 'update_slot') {
        $slotId = (int) admin_input('slot_id');
        $slotCode = strtoupper(admin_clean_text(admin_input('slot_code')));
        $isActive = admin_bool(admin_input('is_active'));
        $manualStatus = admin_clean_text(admin_input('manual_status'));
        $allowedStatuses = ['Auto', 'Available', 'Reserved', 'Occupied', 'Inactive'];

        if ($slotId <= 0 || $slotCode === '') {
            admin_error('Slot details are incomplete.', 422);
        }

        if (!in_array($manualStatus, $allowedStatuses, true)) {
            $manualStatus = 'Auto';
        }

        $lookupStatement = $connection->prepare("
            SELECT floor_id, floor_name
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

        if (admin_slot_exists($connection, (int) ($slot['floor_id'] ?? 0), $slotCode, $slotId)) {
            admin_error('Another slot on this floor already uses that slot code.', 409);
        }

        $statement = $connection->prepare("
            UPDATE parking_slots
            SET floor_name = ?, slot_code = ?, row_label = ?, is_active = ?, manual_status = ?
            WHERE id = ?
        ");
        $rowLabel = parking_row_label_from_slot($slotCode);
        $statement->bind_param('sssisi', $slot['floor_name'], $slotCode, $rowLabel, $isActive, $manualStatus, $slotId);
        $statement->execute();

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_SLOT_UPDATED',
            'description' => 'Admin updated slot ' . $slotCode . ' on ' . (string) ($slot['floor_name'] ?? 'Unknown Floor') . '.',
            'related_floor' => (string) ($slot['floor_name'] ?? ''),
            'related_slot' => $slotCode,
            'status' => $manualStatus !== 'Auto' ? $manualStatus : ($isActive === 1 ? 'Active' : 'Inactive')
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_SLOT_UPDATED', 'Admin updated slot ' . $slotCode . ' on ' . (string) ($slot['floor_name'] ?? 'Unknown Floor') . '.', [
            'target_type' => 'parking_slot',
            'target_id' => (string) $slotId,
            'status' => 'success',
            'metadata' => [
                'floor_id' => $slot['floor_id'] ?? null,
                'floor_name' => $slot['floor_name'] ?? '',
                'slot_code' => $slotCode,
                'is_active' => $isActive,
                'manual_status' => $manualStatus
            ]
        ]);

        admin_success('Parking slot updated successfully.', admin_manage_slots_payload($connection));
    }

    admin_error('Invalid slot management action.');
} catch (Throwable $exception) {
    admin_log('manage-slots-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    $status = (int) $exception->getCode();

    if (($exception instanceof mysqli_sql_exception) && (int) $exception->getCode() === 1062) {
        $status = 409;
    }

    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    $message = $status === 409
        ? 'That floor or slot already exists.'
        : 'Failed to manage parking floors and slots.';

    admin_error($message, $status, [
        'details' => $exception->getMessage()
    ]);
}
