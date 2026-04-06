<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/common.php';
require_once __DIR__ . '/../admin/feedback_common.php';
require_once __DIR__ . '/../common/system-logs.php';

admin_require_method('POST');

try {
    $connection = admin_db();
    $email = strtolower(admin_clean_text(admin_input('email')));
    $message = admin_clean_text(admin_input('message'));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_error('A valid email address is required.');
    }

    if ($message === '') {
        admin_error('Concern message is required.');
    }

    $feedbackColumns = admin_feedback_columns($connection);
    $insertColumns = [];
    $placeholders = [];
    $types = '';
    $params = [];

    if (admin_feedback_has_column($feedbackColumns, 'user_id')) {
        $insertColumns[] = 'user_id';
        $placeholders[] = 'NULLIF(?, 0)';
        $types .= 'i';
        $params[] = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    }

    $insertColumns[] = 'email';
    $placeholders[] = '?';
    $types .= 's';
    $params[] = $email;

    $messageColumn = admin_feedback_primary_message_column($feedbackColumns);
    $insertColumns[] = $messageColumn;
    $placeholders[] = '?';
    $types .= 's';
    $params[] = $message;

    if (admin_feedback_has_column($feedbackColumns, 'status')) {
        $insertColumns[] = 'status';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = 'Pending';
    }

    $dateColumn = admin_feedback_primary_date_column($feedbackColumns);
    $insertColumns[] = $dateColumn;
    $placeholders[] = 'NOW()';

    $statement = $connection->prepare("
        INSERT INTO feedback_messages (" . implode(', ', $insertColumns) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ");
    $statement->bind_param($types, ...$params);
    $statement->execute();
    $messageId = (int) $statement->insert_id;

    $notificationTitle = 'New Feedback Submitted';
    $messagePreview = function_exists('mb_substr') ? mb_substr($message, 0, 120) : substr($message, 0, 120);
    $notificationMessage = "Feedback from {$email}: {$messagePreview}";

    $messageLength = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

    if ($messageLength > 120) {
        $notificationMessage .= '...';
    }

    $notificationStatement = $connection->prepare("
        INSERT INTO notifications (user_id, reservation_id, title, message, audience, notification_date, is_read)
        VALUES (NULL, NULL, ?, ?, 'Booth Staff', CURDATE(), 0)
    ");
    $notificationStatement->bind_param('ss', $notificationTitle, $notificationMessage);
    $notificationStatement->execute();

    system_logs_write($connection, [
        'user_id' => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'actor_role' => 'user',
        'actor_name' => $email,
        'action_type' => 'FEEDBACK_SUBMITTED',
        'description' => 'Feedback submitted: ' . $messagePreview . ($messageLength > 120 ? '...' : ''),
        'status' => 'Pending'
    ]);

    admin_success('Feedback submitted successfully. Thank you for reaching out.', [
        'messageId' => $messageId
    ]);
} catch (Throwable $exception) {
    admin_log('feedback-submit-failed', ['error' => $exception->getMessage()]);
    admin_error('Failed to submit feedback.', 500, [
        'details' => $exception->getMessage()
    ]);
}
