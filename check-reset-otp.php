<?php
declare(strict_types=1);

require_once __DIR__ . '/otp-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./verify-reset-otp.php');
    exit;
}

if (empty($_SESSION['password_reset_user_id']) || empty($_SESSION['password_reset_email'])) {
    otp_json_response(false, 'Your reset session has expired. Please request a new OTP.', 401);
}

try {
    $data = otp_request_data();
    $otp = trim((string) ($data['otp'] ?? ''));

    if (!preg_match('/^\d{6}$/', $otp)) {
        otp_json_response(false, 'OTP must be exactly 6 digits.', 422);
    }

    $connection = otp_db();
    $userId = (int) $_SESSION['password_reset_user_id'];
    $email = (string) $_SESSION['password_reset_email'];

    $user = otp_find_user_for_reset($connection, $userId, $email);

    if (!$user || empty($user['reset_otp_hash']) || empty($user['reset_otp_expires_at'])) {
        otp_json_response(false, 'No active OTP was found. Please request a new one.', 404);
    }

    if (strtotime((string) $user['reset_otp_expires_at']) < time()) {
        error_log(sprintf('[otp] check-reset-otp rejected user %d: code expired', $userId));
        otp_clear_user_code($connection, $userId);
        otp_clear_reset_session();
        otp_json_response(false, 'This OTP has expired. Please request a new one.', 422);
    }

    if (!password_verify($otp, (string) $user['reset_otp_hash'])) {
        // Expired and wrong codes both land here from the user's point of view,
        // so log which it was - the two need very different fixes.
        error_log(sprintf('[otp] check-reset-otp rejected user %d: code did not match', $userId));

        $attemptsLeft = otp_register_failed_attempt();

        if ($attemptsLeft <= 0) {
            otp_clear_user_code($connection, $userId);
            otp_clear_reset_session();
            otp_json_response(
                false,
                'Too many incorrect codes. For your security this OTP has been cancelled - please request a new one.',
                429
            );
        }

        // Only the newest code works, and asking twice is the common way to end
        // up typing a dead one, so say so rather than just "incorrect".
        otp_json_response(
            false,
            sprintf(
                'Incorrect OTP. If you requested more than one code, use the newest email. You have %d attempt%s left.',
                $attemptsLeft,
                $attemptsLeft === 1 ? '' : 's'
            ),
            422
        );
    }

    otp_mark_user_verified($connection, $userId);
    otp_reset_attempts();

    session_regenerate_id(true);
    $_SESSION['password_reset_allowed'] = true;
    $_SESSION['password_reset_verified_at'] = time();

    otp_json_response(true, 'OTP verified successfully. You can now set a new password.', 200, [
        'redirect' => './reset-password.php',
        'expires_in' => OTP_RESET_SESSION_TTL
    ]);
} catch (Throwable $exception) {
    otp_fail($exception, 'check-reset-otp');
}
