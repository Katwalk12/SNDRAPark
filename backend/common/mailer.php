<?php

declare(strict_types=1);

require_once __DIR__ . '/../utils/EnvHelper.php';

/**
 * Outgoing mail for the app.
 *
 * The password-reset OTP had the only working mailer in the project, wired
 * directly into otp-common.php. Everything else that should reach a driver --
 * a booking confirmation, the reminder before a slot is released, a receipt --
 * had nowhere to send from. This is that send path, kept deliberately small:
 * it never throws into a request, it reports success or failure, and the
 * caller decides whether that matters.
 */

if (!function_exists('sndra_mail_load_phpmailer')) {
    function sndra_mail_load_phpmailer(): bool
    {
        static $loaded = null;

        if ($loaded !== null) {
            return $loaded;
        }

        $roots = [
            dirname(__DIR__) . '/PHPMailer-master/src',
            dirname(__DIR__, 2) . '/vendor/phpmailer/phpmailer/src',
            dirname(__DIR__, 2) . '/vendor/PHPMailer/src'
        ];

        foreach ($roots as $path) {
            if (
                file_exists($path . '/Exception.php')
                && file_exists($path . '/PHPMailer.php')
                && file_exists($path . '/SMTP.php')
            ) {
                require_once $path . '/Exception.php';
                require_once $path . '/PHPMailer.php';
                require_once $path . '/SMTP.php';
                $loaded = true;
                return true;
            }
        }

        $loaded = false;
        return false;
    }
}

if (!function_exists('sndra_mail_settings')) {
    function sndra_mail_settings(): array
    {
        $username = trim((string) EnvHelper::get('MAIL_USERNAME', ''));
        // Google prints app passwords in four spaced groups; the spaces are
        // presentational and break authentication if sent through.
        $password = str_replace(' ', '', trim((string) EnvHelper::get('MAIL_PASSWORD', '')));
        $encryption = strtolower(trim((string) EnvHelper::get('MAIL_ENCRYPTION', 'tls')));
        $port = (int) EnvHelper::get('MAIL_PORT', 0);
        $fromEmail = trim((string) EnvHelper::get('MAIL_FROM_EMAIL', ''));

        return [
            'host' => trim((string) EnvHelper::get('MAIL_HOST', 'smtp.gmail.com')) ?: 'smtp.gmail.com',
            'username' => $username,
            'password' => $password,
            'port' => $port > 0 ? $port : ($encryption === 'ssl' ? 465 : 587),
            'encryption' => in_array($encryption, ['tls', 'ssl', 'starttls'], true) ? $encryption : 'tls',
            'from_email' => $fromEmail !== '' ? $fromEmail : $username,
            'from_name' => trim((string) EnvHelper::get('MAIL_FROM_NAME', 'SNDRA Park')) ?: 'SNDRA Park'
        ];
    }
}

if (!function_exists('sndra_mail_send')) {
    /** Returns true when the message was handed to the SMTP server. */
    function sndra_mail_send(string $recipient, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $recipient = trim($recipient);

        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $settings = sndra_mail_settings();

        if ($settings['username'] === '' || $settings['password'] === '') {
            return false;
        }

        if (!sndra_mail_load_phpmailer()) {
            return false;
        }

        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $settings['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $settings['username'];
            $mail->Password = $settings['password'];
            $mail->Port = (int) $settings['port'];
            $mail->SMTPAutoTLS = true;
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            if ($settings['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->setFrom($settings['from_email'], $settings['from_name']);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);
            $mail->send();

            return true;
        } catch (Throwable $exception) {
            error_log('[sndra-mail] ' . $exception->getMessage());
            return false;
        }
    }
}

if (!function_exists('sndra_mail_layout')) {
    /**
     * One shell for every transactional message, so a confirmation, a reminder
     * and a receipt read as the same sender.
     *
     * $rows renders as a label/value table -- the part a driver actually scans
     * for -- and $callout is the one line that carries the message's purpose.
     */
    function sndra_mail_layout(string $heading, string $intro, array $rows = [], string $callout = '', string $footNote = ''): string
    {
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

        $rowsHtml = '';

        foreach ($rows as $label => $value) {
            $rowsHtml .= '<tr>'
                . '<td style="padding:10px 0; color:#8b8b8b; font-size:13px; width:45%;">' . $escape($label) . '</td>'
                . '<td style="padding:10px 0; color:#ffffff; font-size:14px; font-weight:600; text-align:right;">' . $escape($value) . '</td>'
                . '</tr>';
        }

        $calloutHtml = $callout === ''
            ? ''
            : '<div style="margin:24px 0; padding:18px; text-align:center; background:#0b0b0b; border:1px solid #3a2d08; border-radius:14px;">'
                . '<div style="font-size:20px; font-weight:700; letter-spacing:3px; color:#f4c542; word-break:break-all;">'
                . $escape($callout) . '</div></div>';

        $footHtml = $footNote === ''
            ? ''
            : '<p style="margin:18px 0 0; font-size:12px; color:#8b8b8b;">' . $escape($footNote) . '</p>';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . $escape($heading) . '</title></head>'
            . '<body style="margin:0; padding:0; background-color:#050505; font-family:Arial, Helvetica, sans-serif;">'
            . '<div style="padding:32px 16px; background:#050505;">'
            . '<div style="max-width:560px; margin:0 auto; background:#121212; border:1px solid #2a2a2a; border-radius:20px; overflow:hidden;">'
            . '<div style="padding:30px 28px 8px; text-align:center;">'
            . '<div style="font-size:12px; letter-spacing:2px; text-transform:uppercase; color:#d4af37; font-weight:700;">SNDRA Park</div>'
            . '<h1 style="margin:12px 0 0; font-size:24px; line-height:1.3; color:#ffffff;">' . $escape($heading) . '</h1>'
            . '</div>'
            . '<div style="padding:16px 28px 30px; color:#e8e8e8; font-size:15px; line-height:1.7;">'
            . '<p style="margin:0 0 8px;">' . $escape($intro) . '</p>'
            . $calloutHtml
            . ($rowsHtml === '' ? '' : '<table style="width:100%; border-collapse:collapse; border-top:1px solid #2a2a2a;">' . $rowsHtml . '</table>')
            . $footHtml
            . '</div></div></div></body></html>';
    }
}
