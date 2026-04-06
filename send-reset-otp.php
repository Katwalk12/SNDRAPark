<?php
declare(strict_types=1);

require_once __DIR__ . '/otp-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    otp_json_response(false, 'Method not allowed.', 405);
}

try {
    $data = otp_request_data();
    $email = trim((string) ($data['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        otp_json_response(false, 'Please enter a valid email address.', 422);
    }

    $connection = otp_db();
    $user = otp_find_user_by_email($connection, $email);

    if (!$user) {
        otp_json_response(false, 'Email does not exist in our records.', 404);
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);
    $userId = (int) $user['id'];

    otp_store_user_code($connection, $userId, $otpHash, $expiresAt);

    try {
        otp_send_reset_email($connection, $email, $otp);
    } catch (Throwable $mailException) {
        otp_clear_user_code($connection, $userId);

        throw $mailException;
    }

    otp_clear_reset_session();
    $_SESSION['password_reset_user_id'] = $userId;
    $_SESSION['password_reset_email'] = (string) $user['email'];
    $_SESSION['password_reset_allowed'] = false;
    $_SESSION['password_reset_verified_at'] = null;

    otp_json_response(true, 'OTP sent successfully. Please check your email.', 200, [
        'redirect' => './verify-reset-otp.php'
    ]);
} catch (Throwable $exception) {
    otp_json_response(false, $exception->getMessage(), 500);
}
