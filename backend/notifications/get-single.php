<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        booth_json_response([
            'success' => false,
            'message' => 'Method not allowed.'
        ], 405);
    }

    $notificationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $userType = isset($_GET['user_type']) ? (string) $_GET['user_type'] : 'user';

    if ($notificationId <= 0) {
        booth_json_response([
            'success' => false,
            'message' => 'A valid notification id is required.'
        ], 422);
    }

    $connection = booth_db();
    notifications_ensure_read_tracking_schema($connection);
    [$resolvedUserType, $audiences] = notifications_resolve_audiences($userType);
    $viewer = notifications_get_viewer_context($resolvedUserType);
    notifications_require_viewer($resolvedUserType, $viewer);

    $placeholders = implode(',', array_fill(0, count($audiences), '?'));
    $sql = "
        SELECT
            n.id,
            n.user_id,
            n.reservation_id,
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
        WHERE n.id = ?
          AND n.audience IN ({$placeholders})
    ";

    $types = 'sii' . str_repeat('s', count($audiences));
    $params = [$viewer['reader_type'], (int) $viewer['reader_id'], $notificationId, ...$audiences];

    if ($resolvedUserType === 'user') {
        $sql .= " AND (n.user_id IS NULL OR n.user_id = ?) ";
        $types .= 'i';
        $params[] = (int) $viewer['session_user_id'];
    }

    $sql .= ' LIMIT 1';

    $statement = $connection->prepare($sql);
    $statement->bind_param($types, ...$params);
    $statement->execute();
    $notification = $statement->get_result()->fetch_assoc();

    if (!$notification) {
        booth_json_response([
            'success' => false,
            'message' => 'Notification not found.'
        ], 404);
    }

    $notification['is_read'] = (int) ($notification['is_read'] ?? 0);

    booth_json_response([
        'success' => true,
        'user_type' => $resolvedUserType,
        'notification' => $notification
    ]);
} catch (Throwable $exception) {
    booth_log('notifications-get-single-failed', [
        'error' => $exception->getMessage()
    ]);

    booth_json_response([
        'success' => false,
        'message' => 'Failed to load notification details.'
    ], 500);
}
