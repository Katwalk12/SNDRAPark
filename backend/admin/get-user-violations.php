<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/reservation-security.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();
    reservation_security_expire_due_reservations($connection);

    admin_require_method('GET');

    $userId = (int) ($_GET['user_id'] ?? 0);
    $filter = admin_clean_text($_GET['filter'] ?? '');

    if ($userId <= 0) {
        admin_error('A valid user ID is required.');
    }

    $userStatement = $connection->prepare("
        SELECT
            id,
            full_name,
            email,
            COALESCE(status, 'Active') AS status,
            COALESCE(warning_count, 0) AS warning_count,
            first_warning_at,
            COALESCE(account_status, 'active') AS account_status,
            account_locked_until,
            created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $userStatement->bind_param('i', $userId);
    $userStatement->execute();
    $user = $userStatement->get_result()->fetch_assoc() ?: null;

    if (!$user) {
        admin_error('User account not found.', 404);
    }

    $allowedFilters = [
        'warnings' => ['first_warning', 'second_warning', 'third_warning', 'fourth_warning', 'warning_reset'],
        'locks' => ['account_locked'],
        'unlocks' => ['account_unlocked', 'admin_unlock'],
        'expired' => ['barcode_expired']
    ];

    $sql = "
        SELECT
            id,
            user_id,
            violation_type,
            description,
            related_reservation_id,
            created_by,
            created_at
        FROM user_violations
        WHERE user_id = ?
    ";

    $types = 'i';
    $params = [$userId];

    if ($filter !== '' && isset($allowedFilters[$filter])) {
        $placeholders = implode(',', array_fill(0, count($allowedFilters[$filter]), '?'));
        $sql .= " AND violation_type IN ({$placeholders})";
        $types .= str_repeat('s', count($allowedFilters[$filter]));
        foreach ($allowedFilters[$filter] as $type) {
            $params[] = $type;
        }
    }

    $sql .= " ORDER BY created_at DESC, id DESC";
    $statement = $connection->prepare($sql);
    $statement->bind_param($types, ...$params);
    $statement->execute();
    $result = $statement->get_result();

    $violations = [];
    while ($row = $result->fetch_assoc()) {
        $violations[] = $row;
    }

    admin_success('User violation history loaded successfully.', [
        'user' => $user,
        'violations' => $violations,
        'filter' => $filter
    ]);
} catch (Throwable $exception) {
    admin_log('get-user-violations-failed', [
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load user violations history.', 500, [
        'details' => $exception->getMessage()
    ]);
}
