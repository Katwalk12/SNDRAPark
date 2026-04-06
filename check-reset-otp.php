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
        otp_clear_user_code($connection, $userId);
        otp_clear_reset_session();
        otp_json_response(false, 'This OTP has expired. Please request a new one.', 422);
    }

    if (!password_verify($otp, (string) $user['reset_otp_hash'])) {
        otp_json_response(false, 'Incorrect OTP. Please try again.', 422);
    }

    otp_mark_user_verified($connection, $userId);

    session_regenerate_id(true);
    $_SESSION['password_reset_allowed'] = true;
    $_SESSION['password_reset_verified_at'] = time();

    otp_json_response(true, 'OTP verified successfully. You can now set a new password.', 200, [
        'redirect' => './reset-password.php',
        'expires_in' => OTP_RESET_SESSION_TTL
    ]);
} catch (Throwable $exception) {
    otp_json_response(false, $exception->getMessage(), 500);
}
