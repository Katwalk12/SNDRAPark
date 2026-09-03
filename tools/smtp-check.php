<?php
declare(strict_types=1);

/**
 * Command line check for the password-reset mailer.
 *
 * Usage:
 *   php tools/smtp-check.php                 verify the stored settings can log in
 *   php tools/smtp-check.php you@mail.com    also send a test message
 *
 * Reads the same .env values the reset flow uses, so a pass here means
 * forgot-password.php will work. Running it avoids the browser flow, which
 * burns the request rate limit and writes an OTP hash to the user row.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../otp-common.php';

use PHPMailer\PHPMailer\PHPMailer;

$recipient = trim((string) ($argv[1] ?? ''));

if ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Not a valid email address: {$recipient}\n");
    exit(1);
}

try {
    $smtp = otp_get_smtp_settings();
    otp_load_phpmailer();
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL  Could not load mailer settings: {$exception->getMessage()}\n");
    exit(1);
}

printf(
    "host=%s port=%d encryption=%s username=%s password=%s (%d chars)\n",
    $smtp['host'],
    $smtp['port'],
    $smtp['encryption'],
    $smtp['username'],
    str_repeat('*', strlen($smtp['password'])),
    strlen($smtp['password'])
);

// Gmail rejects the account password and wants the full address as the login,
// so catch the two mistakes that produce an identical "could not authenticate".
if (str_contains($smtp['host'], 'gmail') && !str_contains($smtp['username'], '@')) {
    echo "WARN  MAIL_USERNAME should be the full Gmail address, not a display name.\n";
}

if (str_contains($smtp['host'], 'gmail') && strlen($smtp['password']) !== 16) {
    echo "WARN  Gmail app passwords are 16 characters - MAIL_PASSWORD looks wrong.\n";
}

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = $smtp['host'];
$mail->SMTPAuth = true;
$mail->Username = $smtp['username'];
$mail->Password = $smtp['password'];
$mail->Port = (int) $smtp['port'];
$mail->SMTPAutoTLS = true;
$mail->CharSet = 'UTF-8';
$mail->Timeout = 20;

if (in_array($smtp['encryption'], ['tls', 'starttls'], true)) {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
} elseif ($smtp['encryption'] === 'ssl') {
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
}

try {
    if (!$mail->smtpConnect()) {
        throw new RuntimeException($mail->ErrorInfo !== '' ? $mail->ErrorInfo : 'Connection refused.');
    }

    $mail->smtpClose();
    echo "OK    Connected and authenticated with {$smtp['host']}.\n";
} catch (Throwable $exception) {
    $reason = trim((string) $mail->ErrorInfo) ?: $exception->getMessage();
    fwrite(STDERR, "FAIL  {$reason}\n");

    if (str_contains($reason, 'authenticate') || str_contains($reason, '535')) {
        fwrite(STDERR, "      The account or app password was rejected. Create a new app password at\n");
        fwrite(STDERR, "      https://myaccount.google.com/apppasswords and update MAIL_PASSWORD in .env.\n");
    }

    exit(1);
}

if ($recipient === '') {
    echo "      Pass an email address to also send a test message.\n";
    exit(0);
}

try {
    $mail->setFrom($smtp['from_email'], $smtp['from_name']);
    $mail->addAddress($recipient);
    $mail->Subject = 'SNDRA Park - mailer test';
    $mail->Body = 'This is a test message confirming the SNDRA Park password reset mailer is working.';
    $mail->send();

    echo "OK    Test message sent to {$recipient}.\n";
} catch (Throwable $exception) {
    $reason = trim((string) $mail->ErrorInfo) ?: $exception->getMessage();
    fwrite(STDERR, "FAIL  Could not send the test message: {$reason}\n");
    exit(1);
}
