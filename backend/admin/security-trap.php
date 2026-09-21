<?php

declare(strict_types=1);

/**
 * A tripwire on the admin sign-in form.
 *
 * The rate limiter in login.php caps how fast passwords can be guessed. This
 * is the other half: it catches the automated tooling that does the guessing,
 * before it spends a single attempt.
 *
 * The bait is a form field that is in the DOM but not on the screen and not in
 * the tab order. A person filling in this form cannot reach it -- there is no
 * sequence of keystrokes or clicks that puts text in it. A scripted client
 * that walks the form and fills every input it finds does exactly that, which
 * is the whole signal. Because a human cannot trip it by accident, the
 * response can be a hard block rather than a warning.
 *
 * A trip is recorded, alerted on, and the address is refused for an hour.
 */

if (!defined('ADMIN_TRAP_BLOCK_SECONDS')) {
    define('ADMIN_TRAP_BLOCK_SECONDS', 3600);
}

if (!function_exists('admin_trap_field_name')) {
    /**
     * Deliberately dull and unlike a real credential field.
     *
     * Password managers fill things that look like usernames, emails and
     * passwords; naming the bait after any of those would invite them to fill
     * it and get a real administrator blocked. The markup in admin-login.html
     * must use this exact name.
     */
    function admin_trap_field_name(): string
    {
        return 'admin_notes_hp';
    }
}

if (!function_exists('admin_trap_ensure_schema')) {
    function admin_trap_ensure_schema(mysqli $connection): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        $connection->query("
            CREATE TABLE IF NOT EXISTS admin_trap_blocks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                reason VARCHAR(100) NOT NULL,
                attempted_email VARCHAR(150) NULL,
                user_agent VARCHAR(255) NULL,
                blocked_until DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin_trap_ip_until (ip_address, blocked_until),
                INDEX idx_admin_trap_created (created_at)
            )
        ");

        $ensured = true;
    }
}

if (!function_exists('admin_trap_was_triggered')) {
    /**
     * Did this request fill the bait?
     *
     * An empty string is the honest answer from a real browser, which submits
     * the field untouched, so only a non-empty value counts.
     */
    function admin_trap_was_triggered(): bool
    {
        return trim((string) admin_input(admin_trap_field_name(), '')) !== '';
    }
}

if (!function_exists('admin_trap_is_blocked')) {
    /** Is this address inside a block that has not expired yet? */
    function admin_trap_is_blocked(mysqli $connection, string $ipAddress): bool
    {
        admin_trap_ensure_schema($connection);

        $statement = $connection->prepare("
            SELECT 1
            FROM admin_trap_blocks
            WHERE ip_address = ? AND blocked_until > NOW()
            LIMIT 1
        ");
        $statement->bind_param('s', $ipAddress);
        $statement->execute();
        $blocked = (bool) $statement->get_result()->fetch_row();
        $statement->close();

        return $blocked;
    }
}

if (!function_exists('admin_trap_record')) {
    /**
     * Write the block, then say so in the audit log.
     *
     * ADMIN_HONEYPOT_TRIPPED is on the alertable list, so this also puts mail
     * in the administrator's inbox -- somebody pointing a script at the sign-in
     * form is worth interrupting a person for, unlike a mistyped password.
     */
    function admin_trap_record(mysqli $connection, string $ipAddress, string $reason, string $attemptedEmail): void
    {
        admin_trap_ensure_schema($connection);

        // Truncated because the header is attacker-controlled and only the
        // shape of it is ever useful.
        $userAgent = mb_substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
        $blockedUntil = date('Y-m-d H:i:s', time() + ADMIN_TRAP_BLOCK_SECONDS);

        $statement = $connection->prepare("
            INSERT INTO admin_trap_blocks (ip_address, reason, attempted_email, user_agent, blocked_until)
            VALUES (?, ?, ?, ?, ?)
        ");
        $statement->bind_param('sssss', $ipAddress, $reason, $attemptedEmail, $userAgent, $blockedUntil);
        $statement->execute();
        $statement->close();

        admin_audit_log($connection, null, 'ADMIN_HONEYPOT_TRIPPED', 'A scripted sign-in attempt filled the hidden trap field and was blocked.', [
            'target_type' => 'auth',
            'status' => 'failure',
            'admin_email' => $attemptedEmail,
            'ip_address' => $ipAddress,
            'alert_details' => [
                'Blocked address' => $ipAddress,
                'Blocked until' => $blockedUntil,
                'Client' => $userAgent !== '' ? $userAgent : 'not reported'
            ],
            'metadata' => [
                'reason' => $reason,
                'blocked_until' => $blockedUntil,
                'user_agent' => $userAgent
            ]
        ]);
    }
}

if (!function_exists('admin_trap_purge_expired')) {
    /**
     * Blocks are evidence for a while and then they are noise. A week is long
     * enough to look at a pattern the morning after.
     */
    function admin_trap_purge_expired(mysqli $connection): void
    {
        admin_trap_ensure_schema($connection);
        $connection->query("
            DELETE FROM admin_trap_blocks
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
    }
}
