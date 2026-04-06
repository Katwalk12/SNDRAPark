<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

function booth_slot_monitor_floor_config(): array
{
    return [
        'LG' => ['label' => 'LG', 'prefix' => 'L', 'count' => 20, 'aliases' => ['LG', 'LOWER GROUND']],
        '1ST FLOOR' => ['label' => '1st Floor', 'prefix' => 'A', 'count' => 20, 'aliases' => ['1', '1ST', '1ST FLOOR', 'FIRST FLOOR', 'A']],
        '2ND FLOOR' => ['label' => '2nd Floor', 'prefix' => 'B', 'count' => 20, 'aliases' => ['2', '2ND', '2ND FLOOR', 'SECOND FLOOR', 'B']],
        '3RD FLOOR' => ['label' => '3rd Floor', 'prefix' => 'C', 'count' => 20, 'aliases' => ['3', '3RD', '3RD FLOOR', 'THIRD FLOOR', 'C']],
        '4TH FLOOR' => ['label' => '4th Floor', 'prefix' => 'D', 'count' => 20, 'aliases' => ['4', '4TH', '4TH FLOOR', 'FOURTH FLOOR', 'D']],
        '5TH FLOOR' => ['label' => '5th Floor', 'prefix' => 'E', 'count' => 20, 'aliases' => ['5', '5TH', '5TH FLOOR', 'FIFTH FLOOR', 'E']]
    ];
}

function booth_slot_monitor_normalize_floor(string $requestedFloor): string
{
    $normalized = strtoupper(trim($requestedFloor));

    foreach (booth_slot_monitor_floor_config() as $floorKey => $floorConfig) {
        if ($normalized === $floorKey) {
            return $floorConfig['label'];
        }

        foreach ($floorConfig['aliases'] as $alias) {
            if ($normalized === strtoupper($alias)) {
                return $floorConfig['label'];
            }
        }
    }

    return 'LG';
}

function booth_slot_monitor_floor_key(string $selectedFloor): string
{
    foreach (booth_slot_monitor_floor_config() as $floorKey => $floorConfig) {
        if ($floorConfig['label'] === $selectedFloor) {
            return $floorKey;
        }
    }

    return 'LG';
}

function booth_slot_monitor_build_slot_map(string $selectedFloor): array
{
    $config = booth_slot_monitor_floor_config();
    $floorKey = booth_slot_monitor_floor_key($selectedFloor);
    $floorConfig = $config[$floorKey] ?? $config['LG'];
    $slots = [];

    for ($number = 1; $number <= (int) $floorConfig['count']; $number++) {
        $slotName = $floorConfig['prefix'] . $number;
        $slots[$slotName] = [
            'slot' => $slotName,
            'status' => 'available',
            'name' => null,
            'email' => null,
            'barcode' => null,
            'reservation_date' => null,
            'time_in' => null,
            'message' => 'Tap to view'
        ];
    }

    return $slots;
}

function booth_slot_monitor_fetch_active_records(mysqli $connection, string $selectedFloor): array
{
    $sql = booth_build_transaction_query() . "
        WHERE LOWER(TRIM(r.parking_floor)) = LOWER(TRIM(?))
          AND (
                (pt.actual_time_in IS NOT NULL AND pt.actual_time_out IS NULL)
                OR (
                    (UPPER(COALESCE(r.status, 'Reserved')) = 'RESERVED' OR UPPER(COALESCE(pt.payment_status, 'Reserved')) = 'RESERVED')
                    AND (pt.actual_time_in IS NULL OR pt.actual_time_in = '')
                    AND UPPER(COALESCE(r.status, 'Reserved')) NOT IN ('COMPLETED', 'PAID', 'CANCELLED', 'EXITED')
                )
          )
        ORDER BY
            CASE
                WHEN pt.actual_time_in IS NOT NULL AND pt.actual_time_out IS NULL THEN 0
                ELSE 1
            END,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) DESC
    ";

    $statement = $connection->prepare($sql);
    $statement->bind_param('s', $selectedFloor);
    $statement->execute();
    $result = $statement->get_result();
    $records = [];

    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }

    return $records;
}

function booth_slot_monitor_resolve_slot_status(array $record): string
{
    $hasActualTimeIn = !empty($record['actual_time_in']);
    $hasActualTimeOut = !empty($record['actual_time_out']);
    $reservationStatus = strtoupper(trim((string) ($record['reservation_status'] ?? 'RESERVED')));
    $paymentStatus = strtoupper(trim((string) ($record['payment_status'] ?? 'RESERVED')));

    if ($hasActualTimeIn && !$hasActualTimeOut) {
        return 'occupied';
    }

    if (
        !$hasActualTimeIn
        && !in_array($reservationStatus, ['COMPLETED', 'PAID', 'CANCELLED', 'EXITED'], true)
        && !in_array($paymentStatus, ['PAID'], true)
    ) {
        return 'reserved';
    }

    return 'available';
}

function booth_slot_monitor_build_summary(array $slots): array
{
    $summary = ['available' => 0, 'reserved' => 0, 'occupied' => 0];

    foreach ($slots as $slot) {
        $status = $slot['status'] ?? 'available';
        if (isset($summary[$status])) {
            $summary[$status]++;
        }
    }

    return $summary;
}

function booth_build_floor_monitor_payload(mysqli $connection, string $requestedFloor): array
{
    $selectedFloor = booth_slot_monitor_normalize_floor($requestedFloor);
    $slots = booth_slot_monitor_build_slot_map($selectedFloor);
    $records = booth_slot_monitor_fetch_active_records($connection, $selectedFloor);
    $activeReservations = [];

    foreach ($records as $record) {
        $slotName = trim((string) ($record['parking_slot'] ?? ''));

        if ($slotName === '' || !array_key_exists($slotName, $slots) || $slots[$slotName]['status'] !== 'available') {
            continue;
        }

        $status = booth_slot_monitor_resolve_slot_status($record);
        if ($status === 'available') {
            continue;
        }

        $timeIn = $status === 'occupied'
            ? ($record['actual_time_in'] ?? null)
            : ($record['reserved_time_in'] ?? null);

        $slots[$slotName] = [
            'slot' => $slotName,
            'status' => $status,
            'name' => $record['full_name'] ?? null,
            'email' => $record['email'] ?? null,
            'barcode' => $record['barcode_value'] ?? null,
            'reservation_date' => $record['reservation_date'] ?? null,
            'time_in' => $timeIn,
            'message' => $status === 'occupied' ? 'Parking unavailable' : 'Tap to view'
        ];

        $activeReservations[] = [
            'barcode' => $record['barcode_value'] ?? null,
            'name' => $record['full_name'] ?? null,
            'email' => $record['email'] ?? null,
            'floor' => $selectedFloor,
            'slot' => $slotName,
            'reservation_date' => $record['reservation_date'] ?? null,
            'time_in' => $timeIn,
            'status' => $status === 'occupied' ? 'Occupied' : 'Reserved'
        ];
    }

    return [
        'floor' => $selectedFloor,
        'summary' => booth_slot_monitor_build_summary(array_values($slots)),
        'slots' => array_values($slots),
        'active_reservations' => $activeReservations
    ];
}
