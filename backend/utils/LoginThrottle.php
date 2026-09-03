<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Failed login attempt tracking.
 *
 * Users get a limited number of unsuccessful login attempts (5 by default).
 * Once the limit is reached the account is locked for a short period; the user
 * either waits for the lockout to expire or contacts the administrator.
 *
 * This is deliberately kept separate from the reservation-violation lockout
 * (users.account_status / users.account_locked_until) so the two never collide.
 */
class LoginThrottle
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_LOCKOUT_MINUTES = 15;

    private static ?array $policyCache = null;

    /**
     * @return array{max_attempts:int,lockout_seconds:int,lockout_minutes:int}
     */
    public static function policy(): array
    {
        if (self::$policyCache !== null) {
            return self::$policyCache;
        }

        $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;
        $lockoutMinutes = self::DEFAULT_LOCKOUT_MINUTES;

        try {
            $connection = Database::connection();
            $result = $connection->query("
                SELECT setting_key, setting_value
                FROM security_settings
                WHERE setting_key IN ('max_login_attempts', 'login_lockout_minutes')
            ");

            while ($result && ($row = $result->fetch_assoc())) {
                if ($row['setting_key'] === 'max_login_attempts') {
                    $maxAttempts = max(1, (int) $row['setting_value']);
                }

                if ($row['setting_key'] === 'login_lockout_minutes') {
                    $lockoutMinutes = max(1, (int) $row['setting_value']);
                }
            }
        } catch (Throwable $exception) {
            // Keep the defaults when the settings table is unavailable.
        }

        self::$policyCache = [
            'max_attempts' => $maxAttempts,
            'lockout_minutes' => $lockoutMinutes,
            'lockout_seconds' => $lockoutMinutes * 60,
        ];

        return self::$policyCache;
    }

    /**
     * Is the account currently locked out because of failed logins?
     *
     * @return array{locked:bool,seconds_remaining:int,minutes_remaining:int,message:?string}
     */
    public static function lockState(?array $user): array
    {
        $lockedUntil = $user['login_locked_until'] ?? null;
        $timestamp = $lockedUntil ? strtotime((string) $lockedUntil) : false;

        if ($timestamp === false || $timestamp <= time()) {
            return [
                'locked' => false,
                'seconds_remaining' => 0,
                'minutes_remaining' => 0,
                'message' => null,
            ];
        }

        $secondsRemaining = $timestamp - time();
        $minutesRemaining = (int) ceil($secondsRemaining / 60);

        return [
            'locked' => true,
            'seconds_remaining' => $secondsRemaining,
            'minutes_remaining' => $minutesRemaining,
            'message' => self::lockMessage($minutesRemaining),
        ];
    }

    /**
     * Record an unsuccessful login attempt and lock the account when the limit is hit.
     *
     * @return array{locked:bool,attempts:int,remaining_attempts:int,minutes_remaining:int,message:string}
     */
    public static function registerFailure(int $userId, ?array $user = null): array
    {
        $policy = self::policy();
        $attempts = (int) ($user['failed_login_attempts'] ?? 0);
        $lastFailedAt = $user['last_failed_login_at'] ?? null;
        $lastFailedTimestamp = $lastFailedAt ? strtotime((string) $lastFailedAt) : false;

        // Start a fresh window when the previous streak has aged out.
        if ($lastFailedTimestamp !== false && (time() - $lastFailedTimestamp) > $policy['lockout_seconds']) {
            $attempts = 0;
        }

        $attempts++;
        $shouldLock = $attempts >= $policy['max_attempts'];
        $lockedUntil = $shouldLock ? date('Y-m-d H:i:s', time() + $policy['lockout_seconds']) : null;

        self::persistFailure($userId, $attempts, $lockedUntil);

        if ($shouldLock) {
            return [
                'locked' => true,
                'attempts' => $attempts,
                'remaining_attempts' => 0,
                'minutes_remaining' => $policy['lockout_minutes'],
                'message' => self::lockMessage($policy['lockout_minutes']),
            ];
        }

        $remaining = max(0, $policy['max_attempts'] - $attempts);

        return [
            'locked' => false,
            'attempts' => $attempts,
            'remaining_attempts' => $remaining,
            'minutes_remaining' => 0,
            'message' => self::remainingAttemptsMessage($remaining),
        ];
    }

    /**
     * Clear the failed attempt streak (successful login or completed password reset).
     */
    public static function clearFailures(int $userId): void
    {
        try {
            $connection = Database::connection();
            $statement = $connection->prepare('
                UPDATE users
                SET failed_login_attempts = 0,
                    last_failed_login_at = NULL,
                    login_locked_until = NULL
                WHERE id = ?
            ');
            $statement->bind_param('i', $userId);
            $statement->execute();
            $statement->close();
        } catch (Throwable $exception) {
            error_log('LoginThrottle::clearFailures failed: ' . $exception->getMessage());
        }
    }

    /**
     * Standard user-facing lockout message.
     */
    public static function lockMessage(int $minutesRemaining): string
    {
        $policy = self::policy();
        $minutesRemaining = max(1, $minutesRemaining);

        return sprintf(
            'Your account is temporarily locked after %d unsuccessful login attempts. Please try again in %d minute%s, or contact the system administrator for assistance.',
            $policy['max_attempts'],
            $minutesRemaining,
            $minutesRemaining === 1 ? '' : 's'
        );
    }

    /**
     * Warn the user before the account gets locked.
     */
    public static function remainingAttemptsMessage(int $remainingAttempts): string
    {
        if ($remainingAttempts <= 0) {
            return 'Invalid username or password.';
        }

        return sprintf(
            'Invalid username or password. You have %d attempt%s left before your account is temporarily locked.',
            $remainingAttempts,
            $remainingAttempts === 1 ? '' : 's'
        );
    }

    private static function persistFailure(int $userId, int $attempts, ?string $lockedUntil): void
    {
        try {
            $connection = Database::connection();

            // Both timestamps are generated by PHP so the attempt window and the
            // lockout are always compared against the same clock, even when the
            // database server runs in a different timezone.
            $failedAt = date('Y-m-d H:i:s');

            $statement = $connection->prepare('
                UPDATE users
                SET failed_login_attempts = ?,
                    last_failed_login_at = ?,
                    login_locked_until = ?
                WHERE id = ?
            ');
            $statement->bind_param('issi', $attempts, $failedAt, $lockedUntil, $userId);
            $statement->execute();
            $statement->close();
        } catch (Throwable $exception) {
            error_log('LoginThrottle::persistFailure failed: ' . $exception->getMessage());
        }
    }
}
