<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/feedback_common.php';
require_once __DIR__ . '/../common/reservation-security.php';

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

    // Approving an appeal unlocks the account through the same audited path the
    // system uses itself, instead of an admin editing the users table by hand.
    if ($action === 'approve_appeal') {
        $lookup = $connection->prepare("SELECT user_id, email FROM feedback_messages WHERE id = ? LIMIT 1");
        $lookup->bind_param('i', $messageId);
        $lookup->execute();
        $record = $lookup->get_result()->fetch_assoc();

        if (!$record || (int) ($record['user_id'] ?? 0) <= 0) {
            admin_error('That appeal is not linked to an account.', 422);
        }

        $appealUserId = (int) $record['user_id'];
        reservation_security_unlock_user_account(
            $connection,
            $appealUserId,
            true,
            'admin',
            'admin_unlock',
            'Account unlocked after an administrator approved the appeal.'
        );

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

        admin_audit_log($connection, $admin, 'ADMIN_APPEAL_APPROVED', 'Admin approved an appeal and unlocked account #' . $appealUserId . '.', [
            'target_type' => 'user',
            'target_id' => (string) $appealUserId,
            'status' => 'success',
            'metadata' => ['feedback_id' => $messageId, 'email' => (string) ($record['email'] ?? '')]
        ]);

        admin_success('Appeal approved. The account is unlocked and the warnings are cleared.', admin_feedback_payload($connection));
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
