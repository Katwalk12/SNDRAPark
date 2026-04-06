<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        booth_json_response([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    $userType = is_array($payload) && isset($payload['user_type']) ? (string) $payload['user_type'] : 'user';
    $notificationId = is_array($payload) && isset($payload['id']) ? (int) $payload['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);

    $connection = booth_db();
    notifications_ensure_read_tracking_schema($connection);
    [$resolvedUserType, $audiences] = notifications_resolve_audiences($userType);
    $viewer = notifications_get_viewer_context($resolvedUserType);
    notifications_require_viewer($resolvedUserType, $viewer);

    $placeholders = implode(',', array_fill(0, count($audiences), '?'));
    $userScopeSql = '';
    $idScopeSql = '';
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

    if ($notificationId > 0) {
        $idScopeSql = ' AND n.id = ? ';
        $types .= 'i';
        $params[] = $notificationId;
    }

    $statement = $connection->prepare("
        INSERT INTO notification_reads (notification_id, reader_type, reader_id, read_at)
        SELECT n.id, ?, ?, NOW()
        FROM notifications n
        WHERE n.audience IN ({$placeholders})
          {$userScopeSql}
          {$idScopeSql}
        ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)
    ");
    $statement->bind_param($types, ...$params);
    $statement->execute();

    $countTypes = 'si' . str_repeat('s', count($audiences));
    $countParams = [
        $viewer['reader_type'],
        (int) $viewer['reader_id'],
        ...$audiences
    ];

    if ($resolvedUserType === 'user') {
        $countTypes .= 'i';
        $countParams[] = (int) $viewer['session_user_id'];
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
    $countStatement->bind_param($countTypes, ...$countParams);
    $countStatement->execute();
    $countRow = $countStatement->get_result()->fetch_assoc() ?: [];
    $unreadCount = (int) ($countRow['unread_count'] ?? 0);

    booth_json_response([
        'success' => true,
        'user_type' => $resolvedUserType,
        'message' => $notificationId > 0 ? 'Notification marked as read.' : 'Notifications marked as read.',
        'count' => $unreadCount
    ]);
} catch (Throwable $exception) {
    booth_log('notifications-mark-read-failed', [
        'error' => $exception->getMessage()
    ]);

    booth_json_response([
        'success' => false,
        'message' => 'Failed to mark notifications as read.'
    ], 500);
}
