<?php

declare(strict_types=1);

/**
 * Database layer for the Arduino slot-sensor rig.
 *
 * The protocol and the precedence rules live next door in common.php, which is
 * pure and unit-tested. This file is the part that talks to MariaDB.
 *
 * Every timestamp here is written with NOW() and compared with NOW(). PHP is
 * pinned to Asia/Manila by config/app.php, MariaDB runs on the Windows system
 * zone, and nothing reconciles them -- so a PHP-generated timestamp in
 * last_reading_at would either freeze the whole board or free it, all at once,
 * on any machine whose clock is not Manila.
 */

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../common/system-logs.php';

if (!function_exists('parking_sensor_state_for_slot')) {
    /**
     * Read one bay's sensor without taking a lock on it.
     *
     * Called from inside the booking transaction, right after the slot row is
     * locked FOR UPDATE, and deliberately as its own statement. MariaDB 10.4
     * has no `FOR UPDATE OF <table>`, so joining this table into the locking
     * read would exclusively lock the very row the serial bridge rewrites on
     * every edge, and a booking and the bridge would take turns stalling each
     * other for up to innodb_lock_wait_timeout.
     *
     * Reading it unlocked is not a compromise. The lock exists to serialise
     * reservations against each other, and no lock can stop a car rolling into
     * a bay one second after somebody confirmed it.
     *
     * @return array{sensor_state:string,sensor_age_seconds:int|null,sensor_device_id:string,sensor_pin:int|null}
     */
    function parking_sensor_state_for_slot(mysqli $connection, int $slotId): array
    {
        $absent = [
            'sensor_state' => 'none',
            'sensor_age_seconds' => null,
            'sensor_device_id' => '',
            'sensor_pin' => null
        ];

        if ($slotId <= 0) {
            return $absent;
        }

        $statement = $connection->prepare("
            SELECT
                sen.device_id,
                sen.sensor_pin,
                sen.is_enabled,
                sen.is_occupied,
                CASE
                    WHEN sen.last_reading_at IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, sen.last_reading_at, NOW())
                END AS sensor_age_seconds
            FROM parking_slot_sensors sen
            WHERE sen.slot_id = ?
            LIMIT 1
        ");
        $statement->bind_param('i', $slotId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$row) {
            return $absent;
        }

        $age = $row['sensor_age_seconds'] === null ? null : (int) $row['sensor_age_seconds'];

        return [
            'sensor_state' => parking_sensor_classify_reading(
                true,
                (int) $row['is_enabled'] === 1,
                (int) $row['is_occupied'] === 1,
                $age,
                parking_sensor_stale_after_seconds()
            ),
            'sensor_age_seconds' => $age,
            'sensor_device_id' => (string) $row['device_id'],
            'sensor_pin' => (int) $row['sensor_pin']
        ];
    }
}

if (!function_exists('parking_sensor_touch_device')) {
    /**
     * Record that a board is alive and what it just sent.
     *
     * Devices are created on first sight, unlike sensor mappings: knowing a
     * board exists costs nothing and is what lets the admin screen say "UNO-A
     * is reporting pins nobody has mapped". A mapping, by contrast, is an
     * assertion about the physical world and stays an admin's decision.
     */
    function parking_sensor_touch_device(
        mysqli $connection,
        string $deviceId,
        int $sequence,
        string $transport,
        ?string $portLabel = null,
        ?string $firmware = null
    ): void {
        $statement = $connection->prepare("
            INSERT INTO parking_sensor_devices
                (device_id, firmware, transport, port_label, last_seen_at, last_sequence, frame_count)
            VALUES (?, ?, ?, ?, NOW(), ?, 1)
            ON DUPLICATE KEY UPDATE
                firmware = COALESCE(VALUES(firmware), parking_sensor_devices.firmware),
                transport = VALUES(transport),
                port_label = VALUES(port_label),
                last_seen_at = NOW(),
                last_sequence = VALUES(last_sequence),
                frame_count = parking_sensor_devices.frame_count + 1
        ");
        $statement->bind_param('ssssi', $deviceId, $firmware, $transport, $portLabel, $sequence);
        $statement->execute();
        $statement->close();
    }
}

if (!function_exists('parking_sensor_count_reject')) {
    /**
     * A line arrived that did not survive the checksum. Worth a counter, not a log line.
     */
    function parking_sensor_count_reject(mysqli $connection, string $deviceId): void
    {
        $statement = $connection->prepare("
            UPDATE parking_sensor_devices
            SET reject_count = reject_count + 1
            WHERE device_id = ?
        ");
        $statement->bind_param('s', $deviceId);
        $statement->execute();
        $statement->close();
    }
}

if (!function_exists('parking_sensor_apply_readings')) {
    /**
     * Write one frame's readings and report whether anything actually moved.
     *
     * Two statements in the normal case. The heartbeat stamps last_reading_at
     * for every mapped pin on the board, which is what the staleness guard
     * reads; per-pin writes happen only on an edge.
     *
     * The `is_occupied <> ?` guard is what makes this idempotent, and it is
     * also the whole reconnect story: after a bridge restart the database holds
     * the stale value and the frame holds the truth, so any bay that moved
     * while the bridge was down is written on the first frame back. Nothing
     * needs to be forced, and forcing would be actively harmful -- rewriting
     * last_changed_at on every pin would reset the timers the stuck-sensor
     * report is built on.
     *
     * The bridge never inserts a mapping. A reading for a pin nobody has mapped
     * is counted against the device and discarded -- that boundary is what stops
     * a mis-flashed board from inventing bays.
     *
     * @param array<int,int> $readings pin => 0|1
     * @return array{edges:int,mapped:int,unmapped:int[]}
     */
    function parking_sensor_apply_readings(
        mysqli $connection,
        string $deviceId,
        array $readings
    ): array {
        if ($readings === []) {
            return ['edges' => 0, 'mapped' => 0, 'unmapped' => []];
        }

        $mappedPins = parking_sensor_mapped_pins($connection, $deviceId);
        $unmapped = [];
        $edges = 0;
        $mapped = 0;

        $edgeStatement = $connection->prepare("
            UPDATE parking_slot_sensors
            SET is_occupied = ?,
                last_changed_at = NOW(),
                last_reading_at = NOW()
            WHERE device_id = ?
              AND sensor_pin = ?
              AND is_occupied <> ?
        ");

        foreach ($readings as $pin => $value) {
            if (!in_array($pin, $mappedPins, true)) {
                $unmapped[] = $pin;
                continue;
            }

            $mapped++;
            $edgeStatement->bind_param('isii', $value, $deviceId, $pin, $value);
            $edgeStatement->execute();

            if ($edgeStatement->affected_rows > 0) {
                $edges++;
            }
        }

        $edgeStatement->close();

        // Heartbeat last, so a bay that did not move still looks fresh and does
        // not age out into 'offline' while its neighbours keep reporting.
        $heartbeat = $connection->prepare("
            UPDATE parking_slot_sensors
            SET last_reading_at = NOW()
            WHERE device_id = ?
        ");
        $heartbeat->bind_param('s', $deviceId);
        $heartbeat->execute();
        $heartbeat->close();

        if ($unmapped !== []) {
            parking_sensor_record_unmapped($connection, $deviceId, $unmapped);
        }

        return ['edges' => $edges, 'mapped' => $mapped, 'unmapped' => $unmapped];
    }
}

if (!function_exists('parking_sensor_mapped_pins')) {
    /**
     * Which pins on this board an admin has actually assigned to a bay.
     *
     * @return int[]
     */
    function parking_sensor_mapped_pins(mysqli $connection, string $deviceId): array
    {
        $statement = $connection->prepare("
            SELECT sensor_pin
            FROM parking_slot_sensors
            WHERE device_id = ?
        ");
        $statement->bind_param('s', $deviceId);
        $statement->execute();
        $result = $statement->get_result();

        $pins = [];

        while ($row = $result->fetch_assoc()) {
            $pins[] = (int) $row['sensor_pin'];
        }

        $statement->close();

        return $pins;
    }
}

if (!function_exists('parking_sensor_record_unmapped')) {
    /**
     * @param int[] $pins
     */
    function parking_sensor_record_unmapped(mysqli $connection, string $deviceId, array $pins): void
    {
        sort($pins);
        $summary = substr(implode(',', $pins), 0, 255);

        $statement = $connection->prepare("
            UPDATE parking_sensor_devices
            SET unmapped_pins = ?
            WHERE device_id = ?
        ");
        $statement->bind_param('ss', $summary, $deviceId);
        $statement->execute();
        $statement->close();
    }
}

if (!function_exists('parking_sensor_sync_mismatches')) {
    /**
     * Log bays where a vehicle sits in somebody else's held reservation.
     *
     * Edge-triggered through mismatch_flagged_at, and that is the whole point:
     * the rig reports at 1 Hz, so a condition-triggered log would write around
     * eighty-six thousand rows a day for a single stuck bay. One row per
     * episode, and the flag clears when the bay sorts itself out.
     *
     * @return int rows newly flagged
     */
    function parking_sensor_sync_mismatches(mysqli $connection): int
    {
        $staleAfter = parking_sensor_stale_after_seconds();
        $activeSlotSubquery = parking_build_active_slot_subquery();

        $sql = "
            SELECT
                s.id AS slot_id,
                s.floor_name,
                s.slot_code,
                sen.device_id,
                sen.sensor_pin
            FROM parking_slot_sensors sen
            INNER JOIN parking_slots s ON s.id = sen.slot_id
            LEFT JOIN parking_floors f ON f.id = s.floor_id
            LEFT JOIN ({$activeSlotSubquery}) active_slots
                ON active_slots.floor_id = f.id
               AND active_slots.parking_slot = s.slot_code
            WHERE sen.is_enabled = 1
              AND sen.is_occupied = 1
              AND sen.mismatch_flagged_at IS NULL
              AND sen.last_reading_at >= DATE_SUB(NOW(), INTERVAL {$staleAfter} SECOND)
              AND COALESCE(active_slots.active_rank, 0) = 1
        ";

        $result = $connection->query($sql);
        $flagged = 0;

        while ($row = $result->fetch_assoc()) {
            $slotId = (int) $row['slot_id'];

            $statement = $connection->prepare("
                UPDATE parking_slot_sensors
                SET mismatch_flagged_at = NOW()
                WHERE slot_id = ? AND mismatch_flagged_at IS NULL
            ");
            $statement->bind_param('i', $slotId);
            $statement->execute();
            $changed = $statement->affected_rows;
            $statement->close();

            if ($changed < 1) {
                continue;
            }

            $flagged++;

            system_logs_write($connection, [
                'actor_role' => 'system',
                'actor_name' => 'Slot sensor rig',
                'action_type' => 'SENSOR_SLOT_MISMATCH',
                'description' => sprintf(
                    'Sensor %s pin %d sees a vehicle in %s %s, but the reservation holder has not checked in.',
                    (string) $row['device_id'],
                    (int) $row['sensor_pin'],
                    (string) $row['floor_name'],
                    (string) $row['slot_code']
                ),
                'related_floor' => (string) $row['floor_name'],
                'related_slot' => (string) $row['slot_code'],
                'status' => 'warning'
            ]);
        }

        // Clear the flag once the bay is clear or the driver has checked in, so
        // the next genuine episode logs again instead of being swallowed.
        $connection->query("
            UPDATE parking_slot_sensors sen
            SET sen.mismatch_flagged_at = NULL
            WHERE sen.mismatch_flagged_at IS NOT NULL
              AND (
                    sen.is_occupied = 0
                 OR sen.is_enabled = 0
                 OR sen.last_reading_at < DATE_SUB(NOW(), INTERVAL {$staleAfter} SECOND)
              )
        ");

        return $flagged;
    }
}

if (!function_exists('parking_sensor_free_counts_by_floor')) {
    /**
     * Free bays per active floor, in display order, for the entrance LCD.
     *
     * Reads the materialised parking_slots.status rather than recomputing, so
     * the sign and the driver dashboard can never disagree about a number.
     *
     * @return array<string,int>
     */
    function parking_sensor_free_counts_by_floor(mysqli $connection): array
    {
        $result = $connection->query("
            SELECT
                f.floor_name,
                SUM(CASE WHEN s.status = 'Available' THEN 1 ELSE 0 END) AS free_count
            FROM parking_floors f
            LEFT JOIN parking_slots s
                ON s.floor_id = f.id
               AND s.is_active = 1
            WHERE f.is_active = 1
            GROUP BY f.id, f.floor_name, f.sort_order
            ORDER BY f.sort_order ASC, f.floor_name ASC
        ");

        $counts = [];

        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['floor_name']] = (int) $row['free_count'];
        }

        return $counts;
    }
}

if (!function_exists('parking_sensor_health_report')) {
    /**
     * Everything the admin sensor panel needs in one round trip.
     *
     * The stuck list is the one that earns its keep: a sensor reading occupied
     * and unchanged for a day, with nobody checked in, has almost certainly got
     * a dirty lens rather than a car, and its bay is quietly unbookable.
     */
    function parking_sensor_health_report(mysqli $connection): array
    {
        $staleAfter = parking_sensor_stale_after_seconds();

        $devices = [];
        $deviceResult = $connection->query("
            SELECT
                d.device_id,
                d.firmware,
                d.transport,
                d.port_label,
                d.last_seen_at,
                d.frame_count,
                d.reject_count,
                d.unmapped_pins,
                CASE
                    WHEN d.last_seen_at IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, d.last_seen_at, NOW())
                END AS age_seconds,
                (SELECT COUNT(*) FROM parking_slot_sensors sen WHERE sen.device_id = d.device_id) AS mapped_count
            FROM parking_sensor_devices d
            ORDER BY d.device_id ASC
        ");

        while ($row = $deviceResult->fetch_assoc()) {
            $age = $row['age_seconds'] === null ? null : (int) $row['age_seconds'];

            $devices[] = [
                'device_id' => (string) $row['device_id'],
                'firmware' => (string) ($row['firmware'] ?? ''),
                'transport' => (string) $row['transport'],
                'port_label' => (string) ($row['port_label'] ?? ''),
                'last_seen_at' => $row['last_seen_at'],
                'age_seconds' => $age,
                'is_online' => $age !== null && $age <= $staleAfter,
                'frame_count' => (int) $row['frame_count'],
                'reject_count' => (int) $row['reject_count'],
                'unmapped_pins' => (string) ($row['unmapped_pins'] ?? ''),
                'mapped_count' => (int) $row['mapped_count']
            ];
        }

        $sensors = [];
        $sensorResult = $connection->query("
            SELECT
                sen.id,
                sen.slot_id,
                sen.device_id,
                sen.sensor_pin,
                sen.sensor_kind,
                sen.is_enabled,
                sen.is_occupied,
                sen.last_reading_at,
                sen.last_changed_at,
                sen.mismatch_flagged_at,
                s.floor_name,
                s.slot_code,
                s.status,
                CASE
                    WHEN sen.last_reading_at IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, sen.last_reading_at, NOW())
                END AS sensor_age_seconds,
                CASE
                    WHEN sen.last_changed_at IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(SECOND, sen.last_changed_at, NOW())
                END AS changed_age_seconds
            FROM parking_slot_sensors sen
            INNER JOIN parking_slots s ON s.id = sen.slot_id
            ORDER BY s.floor_name ASC, s.slot_code ASC
        ");

        $stuck = [];

        while ($row = $sensorResult->fetch_assoc()) {
            $age = $row['sensor_age_seconds'] === null ? null : (int) $row['sensor_age_seconds'];
            $changedAge = $row['changed_age_seconds'] === null ? null : (int) $row['changed_age_seconds'];

            $state = parking_sensor_classify_reading(
                true,
                (int) $row['is_enabled'] === 1,
                (int) $row['is_occupied'] === 1,
                $age,
                $staleAfter
            );

            $entry = [
                'id' => (int) $row['id'],
                'slot_id' => (int) $row['slot_id'],
                'device_id' => (string) $row['device_id'],
                'sensor_pin' => (int) $row['sensor_pin'],
                'sensor_kind' => (string) $row['sensor_kind'],
                'is_enabled' => (int) $row['is_enabled'],
                'is_occupied' => (int) $row['is_occupied'],
                'floor_name' => (string) $row['floor_name'],
                'slot_code' => (string) $row['slot_code'],
                'slot_status' => (string) $row['status'],
                'sensor_state' => $state,
                'sensor_age_seconds' => $age,
                'changed_age_seconds' => $changedAge,
                'mismatch_flagged_at' => $row['mismatch_flagged_at'],
                'last_reading_at' => $row['last_reading_at'],
                'last_changed_at' => $row['last_changed_at']
            ];

            $sensors[] = $entry;

            if ($state === 'occupied' && $changedAge !== null && $changedAge >= 86400) {
                $stuck[] = $entry;
            }
        }

        return [
            'devices' => $devices,
            'sensors' => $sensors,
            'stuck' => $stuck,
            'stale_after_seconds' => $staleAfter,
            'assignable' => parking_sensor_assignable_bays($connection)
        ];
    }
}

if (!function_exists('parking_sensor_assignable_bays')) {
    /**
     * The floors and bays an admin can point a board at.
     *
     * Exists so the admin screen can offer a floor and then its bays, rather
     * than asking someone to type a primary key. Typing a raw slot id is how a
     * sensor ends up reporting for the wrong bay, and a wrong mapping is worse
     * than no mapping: the bay it really watches looks free while a bay nobody
     * is watching looks taken.
     *
     * Inactive floors and bays are included but flagged. An admin reopening a
     * closed floor should not have to go and wire its sensors afterwards.
     *
     * @return array{floors:array<int,array>,slots:array<int,array>}
     */
    function parking_sensor_assignable_bays(mysqli $connection): array
    {
        $floors = [];
        $floorResult = $connection->query("
            SELECT id, floor_name, COALESCE(NULLIF(floor_label, ''), floor_name) AS floor_label, is_active
            FROM parking_floors
            ORDER BY sort_order ASC, floor_name ASC
        ");

        while ($row = $floorResult->fetch_assoc()) {
            $floors[] = [
                'id' => (int) $row['id'],
                'floor_name' => (string) $row['floor_name'],
                'floor_label' => (string) $row['floor_label'],
                'is_active' => (int) $row['is_active']
            ];
        }

        $slots = [];
        $slotResult = $connection->query("
            SELECT
                s.id,
                s.floor_id,
                s.floor_name,
                s.slot_code,
                s.is_active,
                sen.device_id AS sensor_device_id,
                sen.sensor_pin AS sensor_pin
            FROM parking_slots s
            LEFT JOIN parking_slot_sensors sen ON sen.slot_id = s.id
            INNER JOIN parking_floors f ON f.id = s.floor_id
            ORDER BY f.sort_order ASC, " . parking_slot_sort_expression('s.slot_code') . "
        ");

        while ($row = $slotResult->fetch_assoc()) {
            $slots[] = [
                'id' => (int) $row['id'],
                'floor_id' => (int) $row['floor_id'],
                'floor_name' => (string) $row['floor_name'],
                'slot_code' => (string) $row['slot_code'],
                'is_active' => (int) $row['is_active'],
                // So the picker can say a bay is already taken instead of
                // letting the save fail on the unique index.
                'sensor_device_id' => (string) ($row['sensor_device_id'] ?? ''),
                'sensor_pin' => $row['sensor_pin'] === null ? null : (int) $row['sensor_pin']
            ];
        }

        return ['floors' => $floors, 'slots' => $slots];
    }
}

if (!function_exists('parking_sensor_slot_status_for_pin')) {
    /**
     * The materialised status of the bay a given pin is mapped to.
     *
     * Reads parking_slots.status rather than recomputing the precedence, for
     * the same reason the entrance sign does: the lamp on the bollard and the
     * driver's dashboard must never disagree about whether a bay is spoken for.
     *
     * Null means nobody has mapped that pin. The caller sends nothing at all in
     * that case -- an unmapped board being told 'A' would light a green lamp on
     * a bay the system does not believe exists.
     */
    function parking_sensor_slot_status_for_pin(mysqli $connection, string $deviceId, int $pin): ?string
    {
        $statement = $connection->prepare("
            SELECT s.status
            FROM parking_slot_sensors sen
            INNER JOIN parking_slots s ON s.id = sen.slot_id
            WHERE sen.device_id = ?
              AND sen.sensor_pin = ?
            LIMIT 1
        ");
        $statement->bind_param('si', $deviceId, $pin);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();

        return $row ? (string) $row['status'] : null;
    }
}
