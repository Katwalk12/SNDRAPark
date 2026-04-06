<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');

function admin_notifications_payload(mysqli $connection): array
{
    $notifications = [];
    $result = $connection->query("
        SELECT
            id,
            title,
            message,
            audience,
            notification_date,
            created_at
        FROM notifications
        ORDER BY notification_date DESC, created_at DESC
    ");

    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }

    return ['notifications' => $notifications];
}

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('Notifications loaded successfully.', admin_notifications_payload($connection));
    }

    admin_require_method('POST');
    admin_require_csrf();
    $action = admin_clean_text(admin_input('action'));

    if ($action === 'create') {
        $title = admin_clean_text(admin_input('title'));
        $message = admin_clean_text(admin_input('message'));
        $notificationDate = admin_clean_text(admin_input('notification_date')) ?: date('Y-m-d');
        $audience = admin_clean_text(admin_input('audience')) ?: 'Users';

        if ($title === '' || $message === '') {
            admin_error('Notification title and message are required.');
        }

        $statement = $connection->prepare("
            INSERT INTO notifications (user_id, reservation_id, title, message, audience, notification_date, is_read)
            VALUES (NULL, NULL, ?, ?, ?, ?, 0)
        ");
        $statement->bind_param('ssss', $title, $message, $audience, $notificationDate);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_NOTIFICATION_CREATED', 'Admin created a notification titled "' . $title . '".', [
            'target_type' => 'notification',
            'target_id' => (string) $connection->insert_id,
            'status' => 'success',
            'metadata' => [
                'title' => $title,
                'audience' => $audience,
                'notification_date' => $notificationDate
            ]
        ]);

        admin_success('Notification created successfully.', admin_notifications_payload($connection));
    }

    if ($action === 'delete') {
        $notificationId = (int) admin_input('notification_id');
        $statement = $connection->prepare("DELETE FROM notifications WHERE id = ?");
        $statement->bind_param('i', $notificationId);
        $statement->execute();
        admin_audit_log($connection, $admin, 'ADMIN_NOTIFICATION_DELETED', 'Admin deleted notification #' . $notificationId . '.', [
            'target_type' => 'notification',
            'target_id' => (string) $notificationId,
            'status' => 'success'
        ]);

        admin_success('Notification deleted successfully.', admin_notifications_payload($connection));
    }

    admin_error('Invalid notification action.');
} catch (Throwable $exception) {
    admin_log('manage-notifications-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to manage notifications.', 500, [
        'details' => $exception->getMessage()
    ]);
}
