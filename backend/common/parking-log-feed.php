<?php

declare(strict_types=1);

function parking_log_feed_base_sql(): string
{
    return "
        SELECT
            r.id AS reservation_id,
            'Reservation' AS log_type,
            r.created_at AS log_time,
            r.barcode_value,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(u.email, r.email, '') AS email,
            COALESCE(r.parking_floor, '--') AS parking_floor,
            COALESCE(r.parking_slot, '--') AS parking_slot,
            r.reserved_time_in,
            pt.actual_time_in,
            pt.actual_time_out,
            COALESCE(pt.total_payment, 0) AS total_payment,
            COALESCE(pt.payment_status, 'Reserved') AS payment_status,
            COALESCE(pt.booth_status, r.status, 'Reserved') AS status_label
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id

        UNION ALL

        SELECT
            r.id AS reservation_id,
            'Booth Scan In' AS log_type,
            pt.actual_time_in AS log_time,
            r.barcode_value,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(u.email, r.email, '') AS email,
            COALESCE(r.parking_floor, '--') AS parking_floor,
            COALESCE(r.parking_slot, '--') AS parking_slot,
            r.reserved_time_in,
            pt.actual_time_in,
            pt.actual_time_out,
            COALESCE(pt.total_payment, 0) AS total_payment,
            COALESCE(pt.payment_status, 'Reserved') AS payment_status,
            'Parked' AS status_label
        FROM parking_transactions pt
        INNER JOIN reservations r ON r.id = pt.reservation_id
        LEFT JOIN users u ON u.id = r.user_id
        WHERE pt.actual_time_in IS NOT NULL

        UNION ALL

        SELECT
            r.id AS reservation_id,
            'Booth Scan Out' AS log_type,
            pt.actual_time_out AS log_time,
            r.barcode_value,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(u.email, r.email, '') AS email,
            COALESCE(r.parking_floor, '--') AS parking_floor,
            COALESCE(r.parking_slot, '--') AS parking_slot,
            r.reserved_time_in,
            pt.actual_time_in,
            pt.actual_time_out,
            COALESCE(pt.total_payment, 0) AS total_payment,
            COALESCE(pt.payment_status, 'Unpaid') AS payment_status,
            'Exited' AS status_label
        FROM parking_transactions pt
        INNER JOIN reservations r ON r.id = pt.reservation_id
        LEFT JOIN users u ON u.id = r.user_id
        WHERE pt.actual_time_out IS NOT NULL

        UNION ALL

        SELECT
            r.id AS reservation_id,
            'Payment' AS log_type,
            COALESCE(pt.paid_at, p.paid_at) AS log_time,
            r.barcode_value,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(u.email, r.email, '') AS email,
            COALESCE(r.parking_floor, '--') AS parking_floor,
            COALESCE(r.parking_slot, '--') AS parking_slot,
            r.reserved_time_in,
            pt.actual_time_in,
            pt.actual_time_out,
            COALESCE(pt.total_payment, p.amount, 0) AS total_payment,
            'Paid' AS payment_status,
            'Completed' AS status_label
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        LEFT JOIN payments p ON p.reservation_id = r.id
        WHERE COALESCE(pt.paid_at, p.paid_at) IS NOT NULL
    ";
}

function parking_fetch_log_rows(mysqli $connection, ?int $limit = null): array
{
    $sql = "
        SELECT *
        FROM (" . parking_log_feed_base_sql() . ") logs
        WHERE logs.log_time IS NOT NULL
        ORDER BY logs.log_time DESC
    ";

    if ($limit !== null) {
        $sql .= "\nLIMIT " . max(1, (int) $limit);
    }

    $result = $connection->query($sql);
    $rows = [];

    if (!$result) {
        throw new RuntimeException('Failed to load parking log rows: ' . $connection->error);
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function parking_group_log_rows(array $rows): array
{
    $groups = [];
    $overallTotal = 0.0;

    foreach ($rows as $row) {
        $logTime = (string) ($row['log_time'] ?? '');

        if ($logTime === '') {
            continue;
        }

        $timestamp = strtotime($logTime);

        if ($timestamp === false) {
            continue;
        }

        $dateKey = date('Y-m-d', $timestamp);
        $dateLabel = date('F j, Y', $timestamp);

        if (!isset($groups[$dateKey])) {
            $groups[$dateKey] = [
                'date' => $dateKey,
                'dateLabel' => $dateLabel,
                'dailyTotal' => 0.0,
                'logs' => []
            ];
        }

        if (($row['log_type'] ?? '') === 'Payment') {
            $paymentValue = round((float) ($row['total_payment'] ?? 0), 2);
            $groups[$dateKey]['dailyTotal'] += $paymentValue;
            $overallTotal += $paymentValue;
        }

        $groups[$dateKey]['logs'][] = $row;
    }

    return [
        'overallTotalPayment' => round($overallTotal, 2),
        'groups' => array_values(array_map(static function (array $group): array {
            $group['dailyTotal'] = round((float) ($group['dailyTotal'] ?? 0), 2);
            return $group;
        }, $groups))
    ];
}

function parking_fetch_log_groups(mysqli $connection, ?int $limit = null): array
{
    return parking_group_log_rows(parking_fetch_log_rows($connection, $limit));
}

function parking_fetch_recent_booth_activity(mysqli $connection, int $limit = 20): array
{
    $safeLimit = max(1, $limit);
    $sql = "
        SELECT *
        FROM (" . parking_log_feed_base_sql() . ") logs
        WHERE logs.log_time IS NOT NULL
          AND logs.log_type IN ('Booth Scan In', 'Booth Scan Out', 'Payment')
        ORDER BY logs.log_time DESC
        LIMIT {$safeLimit}
    ";

    $result = $connection->query($sql);
    $records = [];

    if (!$result) {
        throw new RuntimeException('Failed to load booth recent activity: ' . $connection->error);
    }

    while ($row = $result->fetch_assoc()) {
        $records[] = [
            'reservation_id' => isset($row['reservation_id']) ? (int) $row['reservation_id'] : null,
            'log_type' => $row['log_type'] ?? null,
            'log_time' => $row['log_time'] ?? null,
            'barcode_value' => $row['barcode_value'] ?? null,
            'full_name' => $row['full_name'] ?? null,
            'email' => $row['email'] ?? null,
            'parking_floor' => $row['parking_floor'] ?? null,
            'parking_slot' => $row['parking_slot'] ?? null,
            'actual_time_in' => $row['actual_time_in'] ?? null,
            'actual_time_out' => $row['actual_time_out'] ?? null,
            'total_payment' => round((float) ($row['total_payment'] ?? 0), 2),
            'payment_status' => $row['payment_status'] ?? null,
            'status_label' => $row['status_label'] ?? null
        ];
    }

    return $records;
}
