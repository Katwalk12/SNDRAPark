<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/feedback_common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('Feedback messages loaded successfully.', admin_feedback_payload($connection));
    }

    admin_require_method('POST');
    admin_require_csrf();

    $action = admin_clean_text(admin_input('action'));
    $messageId = (int) (admin_input('message_id') ?: admin_input('feedback_id'));

    if ($messageId <= 0) {
        admin_error('A valid feedback record is required.', 422);
    }

    $columns = admin_feedback_columns($connection);

    if ($action === 'resolve') {
        $setClauses = ["status = 'Resolved'"];

        if (admin_feedback_has_column($columns, 'resolved_at')) {
            $setClauses[] = 'resolved_at = NOW()';
        }

        $statement = $connection->prepare("
            UPDATE feedback_messages
            SET " . implode(', ', $setClauses) . "
            WHERE id = ?
        ");
        $statement->bind_param('i', $messageId);
        $statement->execute();

        admin_audit_log($connection, $admin, 'ADMIN_FEEDBACK_RESOLVED', 'Admin resolved feedback message #' . $messageId . '.', [
            'target_type' => 'feedback_message',
            'target_id' => (string) $messageId,
            'status' => 'success'
        ]);

        admin_success('Feedback marked as resolved.', admin_feedback_payload($connection));
    }

    if ($action === 'delete') {
        $statement = $connection->prepare("DELETE FROM feedback_messages WHERE id = ?");
        $statement->bind_param('i', $messageId);
        $statement->execute();

        admin_audit_log($connection, $admin, 'ADMIN_FEEDBACK_DELETED', 'Admin deleted feedback message #' . $messageId . '.', [
            'target_type' => 'feedback_message',
            'target_id' => (string) $messageId,
            'status' => 'success'
        ]);

        admin_success('Feedback deleted successfully.', admin_feedback_payload($connection));
    }

    admin_error('Invalid feedback action.');
} catch (Throwable $exception) {
    admin_log('get-feedback-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load or manage feedback messages.', 500, [
        'details' => $exception->getMessage()
    ]);
}
