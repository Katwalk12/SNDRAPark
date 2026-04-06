<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/feedback_common.php';

$admin = admin_require_auth('admin');

try {
    admin_require_method('POST');
    admin_require_csrf();

    $connection = admin_db();
    $feedbackId = (int) (admin_input('feedback_id') ?: admin_input('message_id'));
    $replyMessage = trim((string) admin_input('admin_reply'));
    $status = admin_feedback_normalize_status(admin_input('status'), 'Replied');

    if ($feedbackId <= 0) {
        admin_error('A valid feedback record is required.', 422);
    }

    if ($replyMessage === '') {
        admin_error('Reply message is required.', 422);
    }

    $record = admin_feedback_fetch_record($connection, $feedbackId);

    if (!$record) {
        admin_error('Feedback record not found.', 404);
    }

    $columns = admin_feedback_columns($connection);
    $setClauses = [];
    $types = '';
    $params = [];

    if (admin_feedback_has_column($columns, 'admin_reply')) {
        $setClauses[] = 'admin_reply = ?';
        $types .= 's';
        $params[] = $replyMessage;
    }

    if (admin_feedback_has_column($columns, 'status')) {
        $setClauses[] = 'status = ?';
        $types .= 's';
        $params[] = $status;
    }

    if (admin_feedback_has_column($columns, 'replied_at')) {
        $setClauses[] = 'replied_at = NOW()';
    }

    if (admin_feedback_has_column($columns, 'resolved_at')) {
        $setClauses[] = $status === 'Resolved' ? 'resolved_at = NOW()' : 'resolved_at = NULL';
    }

    if ($setClauses === []) {
        admin_error('Feedback reply fields are not available in the database.', 500);
    }

    $types .= 'i';
    $params[] = $feedbackId;

    $statement = $connection->prepare("
        UPDATE feedback_messages
        SET " . implode(', ', $setClauses) . "
        WHERE id = ?
    ");
    $statement->bind_param($types, ...$params);
    $statement->execute();

    $userId = (int) ($record['user_id'] ?? 0);
    if ($userId > 0) {
        $notificationTitle = 'Reply to Your Feedback';
        $notificationMessage = 'Admin replied to your concern: ' . $replyMessage;
        $notificationStatement = $connection->prepare("
            INSERT INTO notifications (user_id, reservation_id, title, message, audience, notification_date, is_read)
            VALUES (?, NULL, ?, ?, 'Users', CURDATE(), 0)
        ");
        $notificationStatement->bind_param('iss', $userId, $notificationTitle, $notificationMessage);
        $notificationStatement->execute();
    }

    admin_audit_log($connection, $admin, 'ADMIN_FEEDBACK_REPLIED', 'Admin replied to feedback message #' . $feedbackId . '.', [
        'target_type' => 'feedback_message',
        'target_id' => (string) $feedbackId,
        'status' => 'success',
        'metadata' => [
            'email' => $record['email'] ?? '',
            'reply_status' => $status
        ]
    ]);

    admin_success('Feedback reply saved successfully.', array_merge(
        admin_feedback_payload($connection),
        ['feedback_item' => admin_feedback_fetch_record($connection, $feedbackId)]
    ));
} catch (Throwable $exception) {
    admin_log('reply-feedback-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to save feedback reply.', 500, [
        'details' => $exception->getMessage()
    ]);
}
