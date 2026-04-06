<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/backend/config/database.php';

session_start();
date_default_timezone_set('Asia/Manila');

const OTP_EXPIRY_SECONDS = 300;
const OTP_RESET_SESSION_TTL = 300;

function otp_json_response(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

function otp_request_data(): array
{
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput ?: '', true);

    return is_array($decoded) ? $decoded : $_POST;
}

function otp_db(): mysqli
{
    return Database::connection();
}

function otp_clear_reset_session(): void
{
    unset(
        $_SESSION['password_reset_user_id'],
        $_SESSION['password_reset_email'],
        $_SESSION['password_reset_allowed'],
        $_SESSION['password_reset_verified_at']
    );
}

function otp_mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email;
    }

    [$localPart, $domainPart] = explode('@', $email, 2);
    $visibleLength = strlen($localPart) <= 2 ? 1 : 2;
    $maskedLocal = substr($localPart, 0, $visibleLength)
        . str_repeat('*', max(0, strlen($localPart) - $visibleLength));

    return $maskedLocal . '@' . $domainPart;
}

function otp_load_phpmailer(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $paths = [
        __DIR__ . '/backend/PHPMailer-master/src',
        __DIR__ . '/vendor/phpmailer/phpmailer/src',
        __DIR__ . '/vendor/PHPMailer/src',
    ];

    foreach ($paths as $path) {
        $exceptionFile = $path . '/Exception.php';
        $phpMailerFile = $path . '/PHPMailer.php';
        $smtpFile = $path . '/SMTP.php';

        if (file_exists($exceptionFile) && file_exists($phpMailerFile) && file_exists($smtpFile)) {
            require_once $exceptionFile;
            require_once $phpMailerFile;
            require_once $smtpFile;
            $loaded = true;
            return;
        }
    }

    throw new RuntimeException('PHPMailer files were not found in this project.');
}

function otp_table_exists(mysqli $connection, string $tableName): bool
{
    $safeTableName = $connection->real_escape_string($tableName);
    $result = $connection->query("SHOW TABLES LIKE '{$safeTableName}'");

    return $result !== false && $result->num_rows > 0;
}

function otp_get_table_columns(mysqli $connection, string $tableName): array
{
    $safeTableName = str_replace('`', '``', $tableName);
    $result = $connection->query("SHOW COLUMNS FROM `{$safeTableName}`");
    $columns = [];

    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['Field'];
    }

    return $columns;
}

function otp_get_smtp_settings(mysqli $connection): array
{
    if (!otp_table_exists($connection, 'smtp_settings')) {
        throw new RuntimeException('SMTP settings not configured in database.');
    }

    $smtp = $connection->query('SELECT * FROM smtp_settings LIMIT 1')?->fetch_assoc();

    if (!is_array($smtp)) {
        throw new RuntimeException('SMTP settings not configured in database.');
    }

    $smtpEmail = trim((string) ($smtp['email'] ?? ''));
    $smtpPassword = trim((string) ($smtp['app_password'] ?? ''));
    $smtpHost = trim((string) ($smtp['host'] ?? ''));
    $smtpPort = (int) ($smtp['port'] ?? 0);
    $smtpEncryption = trim((string) ($smtp['encryption'] ?? ''));

    return otp_validate_smtp_settings([
        'host' => $smtpHost,
        'username' => $smtpEmail,
        'password' => $smtpPassword,
        'port' => $smtpPort,
        'encryption' => $smtpEncryption,
        'from_email' => $smtpEmail,
        'from_name' => 'SNDRA Park',
    ]);
}

function otp_validate_smtp_settings(array $settings): array
{
    $host = trim((string) ($settings['host'] ?? 'smtp.gmail.com'));
    $username = trim((string) ($settings['username'] ?? ''));
    $password = trim((string) ($settings['password'] ?? ''));
    $fromEmail = trim((string) ($settings['from_email'] ?? $username));
    $fromName = trim((string) ($settings['from_name'] ?? 'SNDRA Park'));
    $port = (int) ($settings['port'] ?? 0);
    $encryption = strtolower(trim((string) ($settings['encryption'] ?? 'tls')));

    if ($username === '' || $password === '') {
        throw new RuntimeException('SMTP settings not configured in database.');
    }

    if ($fromEmail === '') {
        $fromEmail = $username;
    }

    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    if (!in_array($encryption, ['tls', 'ssl', 'starttls', ''], true)) {
        $encryption = 'tls';
    }

    return [
        'host' => $host !== '' ? $host : 'smtp.gmail.com',
        'username' => $username,
        'password' => $password,
        'port' => $port,
        'encryption' => $encryption,
        'from_email' => $fromEmail,
        'from_name' => $fromName !== '' ? $fromName : 'SNDRA Park',
    ];
}

function otp_send_reset_email(mysqli $connection, string $recipientEmail, string $otp): void
{
    otp_load_phpmailer();
    $smtp = otp_get_smtp_settings($connection);

    $mail = new PHPMailer(true);

    try {
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

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($recipientEmail);
        $mail->isHTML(true);
        $mail->Subject = 'SNDRA Park - Password Reset OTP';
        $mail->Body = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SNDRA Park OTP</title>
</head>
<body style="margin:0; padding:0; background-color:#050505; font-family:Arial, Helvetica, sans-serif; color:#ffffff;">
    <div style="margin:0; padding:32px 16px; background:linear-gradient(180deg, #050505 0%, #111111 100%);">
        <div style="max-width:560px; margin:0 auto; background:#121212; border:1px solid #2a2a2a; border-radius:20px; overflow:hidden; box-shadow:0 12px 40px rgba(0,0,0,0.45);">
            <div style="padding:32px 28px 12px; text-align:center;">
                <div style="font-size:13px; letter-spacing:2px; text-transform:uppercase; color:#d4af37; font-weight:700;">SNDRA Park</div>
                <h1 style="margin:14px 0 0; font-size:28px; line-height:1.3; color:#ffffff;">Password Reset Request</h1>
            </div>

            <div style="padding:20px 28px 32px; color:#e8e8e8; font-size:15px; line-height:1.7;">
                <p style="margin:0 0 16px;">Hello,</p>
                <p style="margin:0 0 16px;">
                    We received a request to reset your password for your <strong style="color:#ffffff;">SNDRA Park</strong> account.
                    Use the one-time password below to continue:
                </p>

                <div style="margin:28px 0; padding:22px 16px; text-align:center; background:#0b0b0b; border:1px solid #3a2d08; border-radius:16px;">
                    <div style="font-size:12px; color:#b8b8b8; letter-spacing:1.5px; text-transform:uppercase; margin-bottom:10px;">Your OTP Code</div>
                    <div style="font-size:36px; line-height:1; font-weight:800; letter-spacing:8px; color:#f4c542;">'
            . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8')
            . '</div>
                </div>

                <p style="margin:0 0 12px;">
                    This OTP will expire in <strong style="color:#f4c542;">5 minutes</strong>.
                </p>
                <p style="margin:0;">
                    If you did not request a password reset, you can safely ignore this email.
                </p>
            </div>

            <div style="padding:18px 28px; border-top:1px solid #242424; background:#0d0d0d; text-align:center; font-size:12px; color:#9a9a9a;">
                &copy; SNDRA Park System
            </div>
        </div>
    </div>
</body>
</html>';
        $mail->AltBody = "SNDRA Park Password Reset OTP\n\nHello,\n\nWe received a request to reset your password.\nYour OTP is: {$otp} (expires in 5 minutes)\n\nIf you did not request this, please ignore this email.\n\n© SNDRA Park System";
        $mail->send();
    } catch (PHPMailerException $exception) {
        $errorDetails = trim((string) $mail->ErrorInfo);
        throw new RuntimeException($errorDetails !== '' ? $errorDetails : $exception->getMessage(), 0, $exception);
    }
}

function otp_find_user_by_email(mysqli $connection, string $email): ?array
{
    $statement = $connection->prepare('
        SELECT id, email
        FROM users
        WHERE email = ?
        LIMIT 1
    ');
    $statement->bind_param('s', $email);
    $statement->execute();

    $user = $statement->get_result()->fetch_assoc();

    return is_array($user) ? $user : null;
}

function otp_find_user_for_reset(mysqli $connection, int $userId, string $email): ?array
{
    $statement = $connection->prepare('
        SELECT id, email, reset_otp_hash, reset_otp_expires_at, reset_otp_verified_at
        FROM users
        WHERE id = ? AND email = ?
        LIMIT 1
    ');
    $statement->bind_param('is', $userId, $email);
    $statement->execute();

    $user = $statement->get_result()->fetch_assoc();

    return is_array($user) ? $user : null;
}

function otp_store_user_code(mysqli $connection, int $userId, string $otpHash, string $expiresAt): void
{
    $statement = $connection->prepare('
        UPDATE users
        SET reset_otp_hash = ?, reset_otp_expires_at = ?, reset_otp_verified_at = NULL
        WHERE id = ?
    ');
    $statement->bind_param('ssi', $otpHash, $expiresAt, $userId);
    $statement->execute();
}

function otp_mark_user_verified(mysqli $connection, int $userId): void
{
    $verifiedAt = date('Y-m-d H:i:s');
    $statement = $connection->prepare('
        UPDATE users
        SET reset_otp_verified_at = ?
        WHERE id = ?
    ');
    $statement->bind_param('si', $verifiedAt, $userId);
    $statement->execute();
}

function otp_clear_user_code(mysqli $connection, int $userId): void
{
    $statement = $connection->prepare('
        UPDATE users
        SET reset_otp_hash = NULL, reset_otp_expires_at = NULL, reset_otp_verified_at = NULL
        WHERE id = ?
    ');
    $statement->bind_param('i', $userId);
    $statement->execute();
}

function otp_get_password_column(mysqli $connection): string
{
    $columns = otp_get_table_columns($connection, 'users');

    if (in_array('password_hash', $columns, true)) {
        return 'password_hash';
    }

    if (in_array('password', $columns, true)) {
        return 'password';
    }

    throw new RuntimeException("The users table must contain a password column.");
}

function otp_has_verified_session(): bool
{
    return !empty($_SESSION['password_reset_allowed'])
        && !empty($_SESSION['password_reset_user_id'])
        && !empty($_SESSION['password_reset_email'])
        && isset($_SESSION['password_reset_verified_at'])
        && (time() - (int) $_SESSION['password_reset_verified_at']) <= OTP_RESET_SESSION_TTL;
}
