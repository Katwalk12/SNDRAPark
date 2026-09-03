<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/common.php';
require_once __DIR__ . '/../common/reservation-security.php';

/**
 * Appeal a locked account.
 *
 * Four expired reservations lock an account, and the lock message told the
 * driver to send "a letter of appeal" to the support address -- with no form,
 * no queue and no record, so an appeal could be lost in an inbox and the
 * unlock, when it happened, was an untracked manual edit.
 *
 * This files the appeal into the feedback inbox the admin already works from.
 * It is deliberately unauthenticated: the whole point is that the person
 * cannot log in.
 */

admin_require_method('POST');

try {
    $connection = admin_db();
    $email = strtolower(admin_clean_text(admin_input('email')));
    $message = admin_clean_text(admin_input('message'));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_error('Enter the email address of the locked account.', 422);
    }

    if (mb_strlen($message) < 20) {
        admin_error('Explain what happened in at least 20 characters so the admin can review it.', 422);
    }

    if (mb_strlen($message) > 2000) {
        admin_error('Keep the appeal under 2000 characters.', 422);
    }

    $statement = $connection->prepare("
        SELECT id, full_name, login_locked_until, COALESCE(warning_count, 0) AS warning_count
        FROM users
        WHERE LOWER(email) = ?
        LIMIT 1
    ");
    $statement->bind_param('s', $email);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();

    // The same answer either way: confirming whether an address has an account,
    // or whether that account is locked, would leak both facts to a stranger.
    $genericResponse = static function (): void {
        admin_success('Your appeal has been submitted. The administrator will review it and reply by email.');
    };

    if (!$user || empty($user['login_locked_until'])) {
        $genericResponse();
    }

    $userId = (int) $user['id'];

    // One open appeal at a time, so a locked driver cannot flood the inbox.
    $existing = $connection->prepare("
        SELECT id FROM feedback_messages
        WHERE user_id = ? AND category = 'Appeal' AND status = 'Pending'
        LIMIT 1
    ");
    $existing->bind_param('i', $userId);
    $existing->execute();

    if ($existing->get_result()->fetch_assoc()) {
        admin_error('You already have an appeal waiting for review. The administrator will reply by email.', 429);
    }

    $body = "ACCOUNT APPEAL\n"
        . 'Locked until: ' . (string) $user['login_locked_until'] . "\n"
        . 'Expired reservations on record: ' . (int) $user['warning_count'] . "\n\n"
        . $message;

    $insert = $connection->prepare("
        INSERT INTO feedback_messages (user_id, email, message, category, status, submitted_at)
        VALUES (?, ?, ?, 'Appeal', 'Pending', NOW())
    ");
    $insert->bind_param('iss', $userId, $email, $body);
    $insert->execute();

    admin_audit_log($connection, null, 'ACCOUNT_APPEAL_SUBMITTED', 'A locked account submitted an appeal.', [
        'target_type' => 'user',
        'target_id' => (string) $userId,
        'status' => 'success',
        'metadata' => ['email' => $email]
    ]);

    admin_success('Your appeal has been submitted. The administrator will review it and reply by email.');
} catch (Throwable $exception) {
    admin_log('submit-appeal-failed', ['error' => $exception->getMessage()]);
    admin_error('Failed to submit the appeal. Please try again.', 500);
}
