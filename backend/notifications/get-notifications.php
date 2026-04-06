<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    $connection = booth_db();
    notifications_ensure_read_tracking_schema($connection);
    $userType = isset($_GET['user_type']) ? (string) $_GET['user_type'] : 'user';
    [$resolvedUserType, $audiences] = notifications_resolve_audiences($userType);
    $viewer = notifications_get_viewer_context($resolvedUserType);
    notifications_require_viewer($resolvedUserType, $viewer);

    $placeholders = implode(',', array_fill(0, count($audiences), '?'));
    $userScopeSql = '';
    $types = 'si' . str_repeat('s', count($audiences));
    $params = [
        $viewer['reader_type'],
        (int) $viewer['reader_id'],
        ...$audiences
    ];

    if ($resolvedUserType === 'user') {
        $userScopeSql = ' AND (n.user_id IS NULL OR n.user_id = ?) ';
        $types .= 'i';
        $params[] = (int) $viewer['session_user_id'];
    }

    $notifications = [];
    $statement = $connection->prepare("
        SELECT
            n.id,
            n.title,
            n.message,
            n.audience,
            CASE WHEN nr.notification_id IS NULL THEN 0 ELSE 1 END AS is_read,
            n.notification_date,
            n.created_at
        FROM notifications n
        LEFT JOIN notification_reads nr
            ON nr.notification_id = n.id
           AND nr.reader_type = ?
           AND nr.reader_id = ?
        WHERE n.audience IN ({$placeholders})
        {$userScopeSql}
        ORDER BY COALESCE(n.notification_date, DATE(n.created_at)) DESC, n.created_at DESC, n.id DESC
        LIMIT 25
    ");
    $statement->bind_param($types, ...$params);
    $statement->execute();
    $result = $statement->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['is_read'] = (int) ($row['is_read'] ?? 0);
        $notifications[] = $row;
    }

    $countStatement = $connection->prepare("
        SELECT COUNT(*) AS unread_count
        FROM notifications n
        LEFT JOIN notification_reads nr
            ON nr.notification_id = n.id
           AND nr.reader_type = ?
           AND nr.reader_id = ?
        WHERE n.audience IN ({$placeholders})
          {$userScopeSql}
          AND nr.notification_id IS NULL
    ");
    $countStatement->bind_param($types, ...$params);
    $countStatement->execute();
    $countRow = $countStatement->get_result()->fetch_assoc();
    $unreadCount = (int) ($countRow['unread_count'] ?? 0);

    booth_json_response([
        'success' => true,
        'user_type' => $resolvedUserType,
        'count' => $unreadCount,
        'notifications' => $notifications
    ]);
} catch (Throwable $exception) {
    booth_log('notifications-get-failed', [
        'error' => $exception->getMessage()
    ]);

    booth_json_response([
        'success' => false,
        'message' => 'Failed to load notifications.',
        'count' => 0,
        'notifications' => []
    ], 500);
}
