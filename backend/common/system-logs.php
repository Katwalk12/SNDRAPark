<?php

declare(strict_types=1);

function system_logs_clean_text($value): ?string
{
    $text = trim((string) ($value ?? ''));
    return $text === '' ? null : $text;
}

function system_logs_action_label(string $actionType): string
{
    $labels = [
        'USER_RESERVATION_CREATED' => 'User Reservation Created',
        'USER_RESERVATION_CANCELLED' => 'User Reservation Cancelled',
        'BARCODE_TIME_IN_SCANNED' => 'Barcode Time In Scan',
        'BARCODE_TIME_OUT_SCANNED' => 'Barcode Time Out Scan',
        'PAYMENT_MARKED_AS_PAID' => 'Payment Marked as Paid',
        'VIOLATION_DETECTED' => 'Violation Detected',
        'ADMIN_FLOOR_CREATED' => 'Admin Floor Created',
        'ADMIN_FLOOR_UPDATED' => 'Admin Floor Updated',
        'ADMIN_FLOOR_DELETED' => 'Admin Floor Deleted',
        'ADMIN_SLOT_CREATED' => 'Admin Slot Created',
        'ADMIN_SLOT_UPDATED' => 'Admin Slot Updated',
        'ADMIN_SLOT_DELETED' => 'Admin Slot Deleted',
        'ADMIN_FEEDBACK_REPLIED' => 'Admin Feedback Replied',
        'FEEDBACK_SUBMITTED' => 'Feedback Submitted'
    ];

    return $labels[$actionType] ?? ucwords(strtolower(str_replace('_', ' ', $actionType)));
}

function system_logs_fetch_user_name(mysqli $connection, int $userId): ?string
{
    if ($userId <= 0) {
        return null;
    }

    $statement = $connection->prepare("
        SELECT full_name, email
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $statement->bind_param('i', $userId);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc() ?: null;

    if (!$user) {
        return null;
    }

    return system_logs_clean_text($user['full_name'] ?? '') ?? system_logs_clean_text($user['email'] ?? '');
}

function system_logs_fetch_reservation_context(mysqli $connection, ?int $reservationId): array
{
    if (($reservationId ?? 0) <= 0) {
        return [
            'related_barcode' => null,
            'related_floor' => null,
            'related_slot' => null
        ];
    }

    $statement = $connection->prepare("
        SELECT barcode_value, parking_floor, parking_slot
        FROM reservations
        WHERE id = ?
        LIMIT 1
    ");
    $statement->bind_param('i', $reservationId);
    $statement->execute();
    $reservation = $statement->get_result()->fetch_assoc() ?: [];

    return [
        'related_barcode' => system_logs_clean_text($reservation['barcode_value'] ?? null),
        'related_floor' => system_logs_clean_text($reservation['parking_floor'] ?? null),
        'related_slot' => system_logs_clean_text($reservation['parking_slot'] ?? null)
    ];
}

function system_logs_write(mysqli $connection, array $entry): int
{
    $userId = isset($entry['user_id']) && (int) $entry['user_id'] > 0 ? (int) $entry['user_id'] : null;
    $actorRole = strtolower(trim((string) ($entry['actor_role'] ?? 'system')));
    $actorName = system_logs_clean_text($entry['actor_name'] ?? null) ?? 'System';
    $actionType = strtoupper(trim((string) ($entry['action_type'] ?? 'SYSTEM_EVENT')));
    $description = system_logs_clean_text($entry['description'] ?? null) ?? system_logs_action_label($actionType);
    $relatedBarcode = system_logs_clean_text($entry['related_barcode'] ?? null);
    $relatedFloor = system_logs_clean_text($entry['related_floor'] ?? null);
    $relatedSlot = system_logs_clean_text($entry['related_slot'] ?? null);
    $amount = isset($entry['amount']) && $entry['amount'] !== '' && $entry['amount'] !== null
        ? round((float) $entry['amount'], 2)
        : null;
    $status = system_logs_clean_text($entry['status'] ?? null);
    $userIdValue = $userId ?? 0;
    $amountValue = $amount !== null ? number_format($amount, 2, '.', '') : '';

    $statement = $connection->prepare("
        INSERT INTO system_logs (
            user_id,
            actor_role,
            actor_name,
            action_type,
            description,
            related_barcode,
            related_floor,
            related_slot,
            amount,
            status,
            created_at
        )
        VALUES (
            NULLIF(?, 0),
            ?,
            ?,
            ?,
            ?,
            NULLIF(?, ''),
            NULLIF(?, ''),
            NULLIF(?, ''),
            NULLIF(?, ''),
            NULLIF(?, ''),
            NOW()
        )
    ");
    $statement->bind_param(
        'isssssssss',
        $userIdValue,
        $actorRole,
        $actorName,
        $actionType,
        $description,
        $relatedBarcode,
        $relatedFloor,
        $relatedSlot,
        $amountValue,
        $status
    );
    $statement->execute();

    return (int) $statement->insert_id;
}

function system_logs_fetch_rows(mysqli $connection, ?array $actionTypes = null, int $limit = 200): array
{
    $limit = max(1, min($limit, 500));
    $sql = "
        SELECT
            id,
            user_id,
            actor_role,
            actor_name,
            action_type,
            description,
            related_barcode,
            related_floor,
            related_slot,
            amount,
            status,
            created_at
        FROM system_logs
    ";

    $types = '';
    $params = [];

    if (is_array($actionTypes) && $actionTypes !== []) {
        $normalizedTypes = array_values(array_filter(array_map(static function ($actionType): string {
            return strtoupper(trim((string) $actionType));
        }, $actionTypes)));

        if ($normalizedTypes !== []) {
            $placeholders = implode(', ', array_fill(0, count($normalizedTypes), '?'));
            $sql .= " WHERE action_type IN ({$placeholders}) ";
            $types .= str_repeat('s', count($normalizedTypes));
            $params = array_merge($params, $normalizedTypes);
        }
    }

    $sql .= " ORDER BY created_at DESC, id DESC LIMIT ? ";
    $types .= 'i';
    $params[] = $limit;

    $statement = $connection->prepare($sql);
    $statement->bind_param($types, ...$params);
    $statement->execute();
    $result = $statement->get_result();

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function system_logs_group_rows(array $rows): array
{
    $groups = [];
    $overallTotal = 0.0;

    foreach ($rows as $row) {
        $timestamp = strtotime((string) ($row['created_at'] ?? ''));

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

        $amount = round((float) ($row['amount'] ?? 0), 2);
        if ($amount > 0) {
            $groups[$dateKey]['dailyTotal'] += $amount;
            $overallTotal += $amount;
        }

        $groups[$dateKey]['logs'][] = [
            'id' => (int) ($row['id'] ?? 0),
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'actor_role' => $row['actor_role'] ?? 'system',
            'actor_name' => $row['actor_name'] ?? 'System',
            'action_type' => $row['action_type'] ?? 'SYSTEM_EVENT',
            'log_type' => system_logs_action_label((string) ($row['action_type'] ?? 'SYSTEM_EVENT')),
            'description' => $row['description'] ?? '',
            'related_barcode' => $row['related_barcode'] ?? null,
            'barcode_value' => $row['related_barcode'] ?? null,
            'related_floor' => $row['related_floor'] ?? null,
            'parking_floor' => $row['related_floor'] ?? null,
            'related_slot' => $row['related_slot'] ?? null,
            'parking_slot' => $row['related_slot'] ?? null,
            'amount' => $amount,
            'total_payment' => $amount,
            'status' => $row['status'] ?? null,
            'status_label' => $row['status'] ?? 'Logged',
            'created_at' => $row['created_at'] ?? null,
            'log_time' => $row['created_at'] ?? null
        ];
    }

    return [
        'overallTotalPayment' => round($overallTotal, 2),
        'groups' => array_values(array_map(static function (array $group): array {
            $group['dailyTotal'] = round((float) ($group['dailyTotal'] ?? 0), 2);
            return $group;
        }, $groups))
    ];
}

function system_logs_fetch_grouped(mysqli $connection, ?array $actionTypes = null, int $limit = 200): array
{
    return system_logs_group_rows(system_logs_fetch_rows($connection, $actionTypes, $limit));
}

function system_logs_fetch_recent_activity(mysqli $connection, int $limit = 20): array
{
    return system_logs_fetch_rows($connection, [
        'BARCODE_TIME_IN_SCANNED',
        'BARCODE_TIME_OUT_SCANNED',
        'PAYMENT_MARKED_AS_PAID'
    ], $limit);
}
