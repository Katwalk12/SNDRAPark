<?php

declare(strict_types=1);

/**
 * Admin control over the Arduino slot-sensor rig.
 *
 * Mapping a pin to a bay is an assertion about the physical world, so only an
 * admin can make it -- the bridge itself never inserts a mapping, which is what
 * stops a mis-flashed board from inventing bays.
 *
 * Its own endpoint rather than more actions on manage_slots.php, which already
 * carries five.
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/system-logs.php';
require_once __DIR__ . '/../parking/common.php';
require_once __DIR__ . '/../iot/sensors.php';

$admin = admin_require_auth('admin');

/**
 * Look a bay up by id, so an error can name it rather than its primary key.
 */
function admin_sensor_find_slot(mysqli $connection, int $slotId): array
{
    $statement = $connection->prepare("
        SELECT id, floor_name, slot_code
        FROM parking_slots
        WHERE id = ?
        LIMIT 1
    ");
    $statement->bind_param('i', $slotId);
    $statement->execute();
    $slot = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$slot) {
        admin_error('Parking slot not found.', 404);
    }

    return $slot;
}

function admin_sensor_label(array $slot): string
{
    return (string) ($slot['floor_name'] ?? 'Unknown Floor') . ' ' . (string) ($slot['slot_code'] ?? 'Unknown Slot');
}

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('Sensor data loaded successfully.', parking_sensor_health_report($connection));
    }

    admin_require_method('POST');
    admin_require_csrf();

    $action = admin_clean_text(admin_input('action'));

    if ($action === 'map_sensor') {
        $slotId = (int) admin_input('slot_id');
        $deviceId = parking_sensor_normalize_device_id((string) admin_input('device_id'));
        $sensorPin = (int) admin_input('sensor_pin');
        $sensorKind = admin_clean_text(admin_input('sensor_kind')) === 'Ultrasonic' ? 'Ultrasonic' : 'IR';

        if ($slotId <= 0) {
            admin_error('A valid slot_id is required.', 422);
        }

        if ($deviceId === '') {
            admin_error('A device id may only contain letters, digits, hyphens and underscores.', 422);
        }

        if ($sensorPin < 0 || $sensorPin > 255) {
            admin_error('A sensor pin must be between 0 and 255.', 422);
        }

        $slot = admin_sensor_find_slot($connection, $slotId);

        // Both uniques are load-bearing, so name which one the admin tripped:
        // "that pin is already on another bay" and "that bay already has a
        // sensor" are different mistakes with different fixes.
        $clash = $connection->prepare("
            SELECT s.floor_name, s.slot_code
            FROM parking_slot_sensors sen
            INNER JOIN parking_slots s ON s.id = sen.slot_id
            WHERE sen.device_id = ? AND sen.sensor_pin = ? AND sen.slot_id <> ?
            LIMIT 1
        ");
        $clash->bind_param('sii', $deviceId, $sensorPin, $slotId);
        $clash->execute();
        $clashRow = $clash->get_result()->fetch_assoc();
        $clash->close();

        // Moving one physical board between bays is the normal case when a rig
        // is being set up, so it is allowed -- but only when the caller says so
        // outright. Silently stealing a pin from another bay would leave that
        // bay reading free forever with nothing watching it.
        $allowMove = admin_bool(admin_input('allow_move')) === 1;

        if ($clashRow && !$allowMove) {
            admin_error(
                'Pin ' . $sensorPin . ' on ' . $deviceId . ' already watches ' . admin_sensor_label($clashRow)
                . '. Reassign it to move the board to this bay instead.',
                409,
                ['requires_move' => true, 'current_bay' => admin_sensor_label($clashRow)]
            );
        }

        $movedFrom = '';

        if ($clashRow && $allowMove) {
            $movedFrom = admin_sensor_label($clashRow);

            $release = $connection->prepare("
                DELETE FROM parking_slot_sensors
                WHERE device_id = ? AND sensor_pin = ? AND slot_id <> ?
            ");
            $release->bind_param('sii', $deviceId, $sensorPin, $slotId);
            $release->execute();
            $release->close();
        }

        $statement = $connection->prepare("
            INSERT INTO parking_slot_sensors (slot_id, device_id, sensor_pin, sensor_kind, is_enabled)
            VALUES (?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                device_id = VALUES(device_id),
                sensor_pin = VALUES(sensor_pin),
                sensor_kind = VALUES(sensor_kind),
                is_enabled = 1,
                updated_at = CURRENT_TIMESTAMP
        ");
        $statement->bind_param('isis', $slotId, $deviceId, $sensorPin, $sensorKind);
        $statement->execute();
        $statement->close();

        parking_sync_slot_statuses($connection, true);

        $description = $movedFrom !== ''
            ? 'Admin moved ' . $deviceId . ' pin ' . $sensorPin . ' from ' . $movedFrom . ' to ' . admin_sensor_label($slot) . '.'
            : 'Admin mapped ' . $deviceId . ' pin ' . $sensorPin . ' to ' . admin_sensor_label($slot) . '.';

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_SENSOR_MAPPED',
            'description' => $description,
            'related_floor' => (string) $slot['floor_name'],
            'related_slot' => (string) $slot['slot_code'],
            'status' => 'Created'
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_SENSOR_MAPPED', $description, [
            'target_type' => 'parking_slot_sensor',
            'target_id' => (string) $slotId,
            'status' => 'success',
            'metadata' => [
                'device_id' => $deviceId,
                'sensor_pin' => $sensorPin,
                'sensor_kind' => $sensorKind,
                'moved_from' => $movedFrom
            ]
        ]);

        admin_success(
            $movedFrom !== ''
                ? 'Sensor moved from ' . $movedFrom . ' to ' . admin_sensor_label($slot) . '.'
                : 'Sensor assigned to ' . admin_sensor_label($slot) . '.',
            parking_sensor_health_report($connection)
        );
    }

    if ($action === 'unmap_sensor') {
        $slotId = (int) admin_input('slot_id');

        if ($slotId <= 0) {
            admin_error('A valid slot_id is required.', 422);
        }

        $slot = admin_sensor_find_slot($connection, $slotId);

        $statement = $connection->prepare("DELETE FROM parking_slot_sensors WHERE slot_id = ?");
        $statement->bind_param('i', $slotId);
        $statement->execute();
        $removed = $statement->affected_rows;
        $statement->close();

        if ($removed < 1) {
            admin_error('That slot has no sensor mapped to it.', 404);
        }

        parking_sync_slot_statuses($connection, true);

        $description = 'Admin removed the sensor mapping on ' . admin_sensor_label($slot) . '.';

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_SENSOR_UNMAPPED',
            'description' => $description,
            'related_floor' => (string) $slot['floor_name'],
            'related_slot' => (string) $slot['slot_code'],
            'status' => 'Deleted'
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_SENSOR_UNMAPPED', $description, [
            'target_type' => 'parking_slot_sensor',
            'target_id' => (string) $slotId,
            'status' => 'success'
        ]);

        admin_success('Sensor mapping removed.', parking_sensor_health_report($connection));
    }

    if ($action === 'set_sensor_enabled') {
        $slotId = (int) admin_input('slot_id');
        $isEnabled = admin_bool(admin_input('is_enabled')) ? 1 : 0;

        if ($slotId <= 0) {
            admin_error('A valid slot_id is required.', 422);
        }

        $slot = admin_sensor_find_slot($connection, $slotId);

        $statement = $connection->prepare("
            UPDATE parking_slot_sensors
            SET is_enabled = ?,
                mismatch_flagged_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE slot_id = ?
        ");
        $statement->bind_param('ii', $isEnabled, $slotId);
        $statement->execute();
        $changed = $statement->affected_rows;
        $statement->close();

        if ($changed < 1) {
            admin_error('That slot has no sensor mapped to it.', 404);
        }

        // Forced, because disabling a sensor is the escape hatch for a bay that
        // a dirty lens has made unbookable. An admin pressing it expects the bay
        // back now, not on the next throttled sweep.
        parking_sync_slot_statuses($connection, true);

        $description = $isEnabled === 1
            ? 'Admin re-enabled the sensor on ' . admin_sensor_label($slot) . '.'
            : 'Admin disabled the sensor on ' . admin_sensor_label($slot) . ', releasing the bay to reservation data.';

        system_logs_write($connection, [
            'actor_role' => 'admin',
            'actor_name' => (string) ($admin['fullName'] ?? $admin['email'] ?? 'Administrator'),
            'action_type' => 'ADMIN_SENSOR_TOGGLED',
            'description' => $description,
            'related_floor' => (string) $slot['floor_name'],
            'related_slot' => (string) $slot['slot_code'],
            'status' => $isEnabled === 1 ? 'Enabled' : 'Disabled'
        ]);
        admin_audit_log($connection, $admin, 'ADMIN_SENSOR_TOGGLED', $description, [
            'target_type' => 'parking_slot_sensor',
            'target_id' => (string) $slotId,
            'status' => 'success',
            'metadata' => ['is_enabled' => $isEnabled]
        ]);

        admin_success(
            $isEnabled === 1 ? 'Sensor re-enabled.' : 'Sensor disabled.',
            parking_sensor_health_report($connection)
        );
    }

    admin_error('Unknown sensor action.', 422);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    admin_log('manage-sensors-failed', [
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    admin_error(
        admin_safe_error_message($exception, $status, 'Failed to update sensors.'),
        $status,
        admin_debug_details($exception)
    );
}
