<?php

declare(strict_types=1);

require_once __DIR__ . '/system-logs.php';
require_once __DIR__ . '/../config/system-settings.php';

/**
 * No-show policy.
 *
 * A reservation whose barcode is never scanned expires, releases the slot back
 * to Available, and costs the driver one strike. Three strikes are warnings;
 * the fourth locks the account with no automatic release date, so it stays
 * locked until an administrator approves a letter of appeal.
 */
const RESERVATION_SECURITY_WARNING_ALLOWANCE = 3;

/** Fallback grace period, in minutes, when the settings table cannot be read. */
const RESERVATION_SECURITY_DEFAULT_GRACE_MINUTES = 30;

/**
 * The three numbers that decide who gets locked out now come from the settings
 * table, so an owner can loosen them without a deploy. The constants above stay
 * as the fallback for a database that has not been migrated yet.
 */
function reservation_security_grace_minutes(?mysqli $connection = null): int
{
    try {
        return (int) system_settings_value('reservation_grace_minutes', $connection);
    } catch (Throwable $exception) {
        return RESERVATION_SECURITY_DEFAULT_GRACE_MINUTES;
    }
}

function reservation_security_warning_allowance(?mysqli $connection = null): int
{
    try {
        return (int) system_settings_value('reservation_warning_allowance', $connection);
    } catch (Throwable $exception) {
        return RESERVATION_SECURITY_WARNING_ALLOWANCE;
    }
}

function reservation_security_warning_window_seconds(?mysqli $connection = null): int
{
    try {
        return ((int) system_settings_value('reservation_warning_window_days', $connection)) * 86400;
    } catch (Throwable $exception) {
        return RESERVATION_SECURITY_WARNING_WINDOW_SECONDS;
    }
}

/** Strikes fall off if the fourth never arrives inside this window. */
const RESERVATION_SECURITY_WARNING_WINDOW_SECONDS = 2592000; // 30 days

function reservation_security_support_email(mysqli $connection): string
{
    try {
        $settings = system_settings_fetch($connection);
        $email = trim((string) ($settings['gmail_address'] ?? ''));
    } catch (Throwable $exception) {
        $email = '';
    }

    return $email !== '' ? $email : 'sndraparksupport@gmail.com';
}

function reservation_security_appeal_instruction(mysqli $connection): string
{
    return 'To have it reactivated, file a letter of appeal to '
        . reservation_security_support_email($connection)
        . ' explaining the missed reservations. An administrator will review it and restore your access.';
}

function reservation_security_cache_dir(): string
{
    $cacheDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

    if (!is_dir($cacheDirectory)) {
        mkdir($cacheDirectory, 0777, true);
    }

    return $cacheDirectory;
}

function reservation_security_should_run(string $cacheKey, int $intervalSeconds): bool
{
    $cacheFile = reservation_security_cache_dir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9._-]+/i', '-', $cacheKey) . '.json';
    $now = time();

    if (is_file($cacheFile)) {
        $payload = json_decode((string) file_get_contents($cacheFile), true);
        $lastRun = (int) ($payload['last_run'] ?? 0);

        if ($lastRun > 0 && ($now - $lastRun) < max(1, $intervalSeconds)) {
            return false;
        }
    }

    file_put_contents($cacheFile, json_encode([
        'last_run' => $now
    ], JSON_UNESCAPED_SLASHES));

    return true;
}

function reservation_security_database_now(mysqli $connection, string $format = '%Y-%m-%d %H:%i:%s'): string
{
    $statement = $connection->prepare("SELECT DATE_FORMAT(NOW(), ?) AS current_value");
    $statement->bind_param('s', $format);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc() ?: [];

    return (string) ($row['current_value'] ?? '');
}

function reservation_security_format_datetime_for_message(?string $value): string
{
    if (!$value) {
        return 'a later date';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return (string) $value;
    }

    return date('F j, Y g:i A', $timestamp);
}

function reservation_security_create_notification(
    mysqli $connection,
    string $title,
    string $message,
    string $audience = 'Users',
    ?int $userId = null,
    ?int $reservationId = null
): void {
    $notificationDate = reservation_security_database_now($connection, '%Y-%m-%d');
    $statement = $connection->prepare("
        INSERT INTO notifications (
            user_id,
            reservation_id,
            title,
            message,
            audience,
            notification_date,
            is_read,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
    ");
    $statement->bind_param('iissss', $userId, $reservationId, $title, $message, $audience, $notificationDate);
    $statement->execute();
}

function reservation_security_log_violation(
    mysqli $connection,
    int $userId,
    string $violationType,
    string $description,
    ?int $reservationId = null,
    string $createdBy = 'system'
): void {
    if ($userId <= 0 || trim($violationType) === '' || trim($description) === '') {
        return;
    }

    $statement = $connection->prepare("
        INSERT INTO user_violations (
            user_id,
            violation_type,
            description,
            related_reservation_id,
            created_by,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $statement->bind_param('issis', $userId, $violationType, $description, $reservationId, $createdBy);
    $statement->execute();

    $userName = system_logs_fetch_user_name($connection, $userId) ?? 'User';
    $reservationContext = system_logs_fetch_reservation_context($connection, $reservationId);

    system_logs_write($connection, [
        'user_id' => $userId,
        'actor_role' => 'user',
        'actor_name' => $userName,
        'action_type' => 'VIOLATION_DETECTED',
        'description' => $description,
        'related_barcode' => $reservationContext['related_barcode'] ?? null,
        'related_floor' => $reservationContext['related_floor'] ?? null,
        'related_slot' => $reservationContext['related_slot'] ?? null,
        'status' => strtoupper(trim($violationType)) !== '' ? strtoupper(trim($violationType)) : 'Violation'
    ]);
}

function reservation_security_get_user_state(mysqli $connection, int $userId, bool $forUpdate = false): ?array
{
    $sql = "
        SELECT
            id,
            email,
            full_name,
            COALESCE(status, 'Active') AS status,
            COALESCE(account_status, 'active') AS account_status,
            COALESCE(warning_count, 0) AS warning_count,
            first_warning_at,
            account_locked_until
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->bind_param('i', $userId);
    $statement->execute();

    return $statement->get_result()->fetch_assoc() ?: null;
}

function reservation_security_reset_warning_state(mysqli $connection, int $userId): void
{
    $statement = $connection->prepare("
        UPDATE users
        SET
            warning_count = 0,
            first_warning_at = NULL
        WHERE id = ?
    ");
    $statement->bind_param('i', $userId);
    $statement->execute();

    if ($statement->affected_rows > 0) {
        reservation_security_log_violation(
            $connection,
            $userId,
            'warning_reset',
            'The active warning window expired after 30 days without reaching the fourth barcode expiration.',
            null,
            'system'
        );
    }
}

function reservation_security_unlock_user_account(
    mysqli $connection,
    int $userId,
    bool $notify = true,
    string $createdBy = 'system',
    string $violationType = 'account_unlocked',
    ?string $description = null
): void
{
    $statement = $connection->prepare("
        UPDATE users
        SET
            account_status = 'active',
            account_locked_until = NULL,
            warning_count = 0,
            first_warning_at = NULL
        WHERE id = ?
    ");
    $statement->bind_param('i', $userId);
    $statement->execute();

    if ($statement->affected_rows > 0) {
        reservation_security_log_violation(
            $connection,
            $userId,
            $violationType,
            $description ?: 'The account lock period ended and the user account was restored automatically.',
            null,
            $createdBy
        );
    }

    if ($notify) {
        reservation_security_create_notification(
            $connection,
            'Account Restored',
            'Your account is now active again and you may continue making reservations.',
            'Users',
            $userId,
            null
        );
    }
}

function reservation_security_sync_user_account(mysqli $connection, int $userId, bool $forUpdate = false): ?array
{
    $user = reservation_security_get_user_state($connection, $userId, $forUpdate);

    if (!$user) {
        return null;
    }

    $nowTimestamp = strtotime(reservation_security_database_now($connection));
    $lockUntilTimestamp = !empty($user['account_locked_until']) ? strtotime((string) $user['account_locked_until']) : false;
    $firstWarningTimestamp = !empty($user['first_warning_at']) ? strtotime((string) $user['first_warning_at']) : false;

    if (($user['account_status'] ?? 'active') === 'locked' && $lockUntilTimestamp !== false && $lockUntilTimestamp <= $nowTimestamp) {
        reservation_security_unlock_user_account($connection, $userId, true);
        return reservation_security_get_user_state($connection, $userId, $forUpdate);
    }

    if (($user['account_status'] ?? 'active') !== 'locked'
        && (int) ($user['warning_count'] ?? 0) > 0
        && $firstWarningTimestamp !== false
        && ($nowTimestamp - $firstWarningTimestamp) >= reservation_security_warning_window_seconds($connection)
    ) {
        reservation_security_reset_warning_state($connection, $userId);
        return reservation_security_get_user_state($connection, $userId, $forUpdate);
    }

    return $user;
}

/**
 * A lock with no release date is an appeal lock; only an administrator can
 * lift it. Locks that still carry a date are legacy timed locks, which the
 * sync routine releases on its own.
 */
function reservation_security_build_login_lock_message(mysqli $connection, ?string $lockedUntil): string
{
    if (empty($lockedUntil)) {
        return 'Your account is locked because too many reservations expired without you arriving at the parking lot. '
            . reservation_security_appeal_instruction($connection);
    }

    return 'Your account is temporarily locked until '
        . reservation_security_format_datetime_for_message($lockedUntil)
        . ' due to repeated expired reservations.';
}

function reservation_security_build_reservation_lock_message(mysqli $connection, ?string $lockedUntil): string
{
    if (empty($lockedUntil)) {
        return 'Your account is locked and cannot create a new reservation. '
            . reservation_security_appeal_instruction($connection);
    }

    return 'Your account is temporarily locked and cannot create a new reservation until '
        . reservation_security_format_datetime_for_message($lockedUntil)
        . '.';
}

function reservation_security_assert_user_can_login(mysqli $connection, int $userId): array
{
    $user = reservation_security_sync_user_account($connection, $userId, false);

    if (!$user) {
        throw new RuntimeException('User account was not found.', 404);
    }

    if (($user['status'] ?? 'Active') !== 'Active') {
        throw new RuntimeException('This account is currently disabled. Please contact the administrator.', 403);
    }

    if (($user['account_status'] ?? 'active') === 'locked') {
        throw new RuntimeException(
            reservation_security_build_login_lock_message($connection, $user['account_locked_until'] ?? null),
            423
        );
    }

    return $user;
}

function reservation_security_assert_user_can_reserve(mysqli $connection, int $userId): array
{
    $user = reservation_security_sync_user_account($connection, $userId, false);

    if (!$user) {
        throw new RuntimeException('User account was not found.', 404);
    }

    if (($user['status'] ?? 'Active') !== 'Active') {
        throw new RuntimeException('This account is currently disabled. Please contact the administrator.', 403);
    }

    if (($user['account_status'] ?? 'active') === 'locked') {
        throw new RuntimeException(
            reservation_security_build_reservation_lock_message($connection, $user['account_locked_until'] ?? null),
            423
        );
    }

    return $user;
}

function reservation_security_fetch_reservation_state(mysqli $connection, int $reservationId, bool $forUpdate = false): ?array
{
    $sql = "
        SELECT
            r.id,
            r.user_id,
            r.barcode_value,
            COALESCE(r.barcode_status, 'active') AS barcode_status,
            r.status,
            r.created_at,
            r.reservation_date,
            r.reserved_time_in,
            pt.actual_time_in,
            pt.actual_time_out
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE r.id = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->bind_param('i', $reservationId);
    $statement->execute();

    return $statement->get_result()->fetch_assoc() ?: null;
}

/**
 * The moment a booking stops being valid.
 *
 * This used to count from created_at, so a slot booked in the morning for a
 * 6pm arrival died at 09:30 and cost the driver a warning for a time that had
 * not come yet. The grace period runs from the reserved arrival instead, and
 * its length is a setting rather than a hard-coded 1800 seconds.
 */
function reservation_security_deadline_timestamp(array $reservation, ?int $graceMinutes = null): ?int
{
    $graceMinutes = $graceMinutes ?? RESERVATION_SECURITY_DEFAULT_GRACE_MINUTES;
    $date = trim((string) ($reservation['reservation_date'] ?? ''));
    $time = trim((string) ($reservation['reserved_time_in'] ?? ''));

    if ($date !== '') {
        $arrival = strtotime(trim($date . ' ' . ($time !== '' ? $time : '00:00:00')));

        if ($arrival !== false) {
            return $arrival + ($graceMinutes * 60);
        }
    }

    // Older rows predate reserved_time_in; fall back to when they were made.
    $createdAtTimestamp = !empty($reservation['created_at']) ? strtotime((string) $reservation['created_at']) : false;

    return $createdAtTimestamp === false ? null : $createdAtTimestamp + ($graceMinutes * 60);
}

function reservation_security_reservation_is_due_for_expiration(array $reservation, ?int $nowTimestamp = null, ?int $graceMinutes = null): bool
{
    $barcodeStatus = strtolower(trim((string) ($reservation['barcode_status'] ?? 'active')));

    if ($barcodeStatus !== 'active') {
        return false;
    }

    if (!empty($reservation['actual_time_in']) || !empty($reservation['actual_time_out'])) {
        return false;
    }

    $deadline = reservation_security_deadline_timestamp($reservation, $graceMinutes);

    if ($deadline === null) {
        return false;
    }

    $nowTimestamp = $nowTimestamp ?? strtotime('now');

    return $nowTimestamp >= $deadline;
}

function reservation_security_ordinal(int $number): string
{
    $names = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];

    return $names[$number] ?? ($number . 'th');
}

function reservation_security_apply_expiration_penalty(mysqli $connection, int $userId, int $reservationId): array
{
    $user = reservation_security_sync_user_account($connection, $userId, true);

    if (!$user) {
        return [
            'warning_count' => 0,
            'account_status' => 'active',
            'account_locked_until' => null
        ];
    }

    $now = reservation_security_database_now($connection);
    $nowTimestamp = strtotime($now) ?: time();
    $warningCount = (int) ($user['warning_count'] ?? 0);
    $firstWarningTimestamp = !empty($user['first_warning_at']) ? strtotime((string) $user['first_warning_at']) : false;

    // Several reservations can fall due in the same sweep. Once the account is
    // locked, keep counting for the record but do not announce the lock again.
    if (($user['account_status'] ?? 'active') === 'locked') {
        $carriedCount = $warningCount + 1;
        $statement = $connection->prepare("UPDATE users SET warning_count = ? WHERE id = ?");
        $statement->bind_param('ii', $carriedCount, $userId);
        $statement->execute();

        return [
            'warning_count' => $carriedCount,
            'account_status' => 'locked',
            'account_locked_until' => $user['account_locked_until'] ?? null
        ];
    }

    // Strikes only stack while the window from the first one is still open;
    // outside it this expiration starts a fresh count.
    $withinWarningWindow = $warningCount >= 1
        && $firstWarningTimestamp !== false
        && ($nowTimestamp - $firstWarningTimestamp) < reservation_security_warning_window_seconds($connection);

    $strikeNumber = $withinWarningWindow ? $warningCount + 1 : 1;

    if ($strikeNumber > reservation_security_warning_allowance($connection)) {
        // Fourth strike. No release date is set, so the account stays locked
        // until an administrator acts on a letter of appeal.
        $statement = $connection->prepare("
            UPDATE users
            SET
                warning_count = ?,
                account_status = 'locked',
                account_locked_until = NULL
            WHERE id = ?
        ");
        $statement->bind_param('ii', $strikeNumber, $userId);
        $statement->execute();

        reservation_security_log_violation(
            $connection,
            $userId,
            'fourth_warning',
            sprintf(
                'A %s reservation barcode expired within the %d-day warning window.',
                reservation_security_ordinal($strikeNumber),
                (int) (reservation_security_warning_window_seconds($connection) / 86400)
            ),
            $reservationId,
            'system'
        );
        reservation_security_log_violation(
            $connection,
            $userId,
            'account_locked',
            'The user account was locked pending a letter of appeal after '
                . $strikeNumber . ' expired reservations.',
            $reservationId,
            'system'
        );

        reservation_security_create_notification(
            $connection,
            'Account Locked',
            'Your account has been locked after ' . $strikeNumber
                . ' reservations expired without you arriving at the parking lot. '
                . reservation_security_appeal_instruction($connection),
            'Users',
            $userId,
            $reservationId
        );

        return [
            'warning_count' => $strikeNumber,
            'account_status' => 'locked',
            'account_locked_until' => null
        ];
    }

    // Strikes 1 through 3: warn, and keep the window anchored on the first.
    if ($strikeNumber === 1) {
        $statement = $connection->prepare("
            UPDATE users
            SET
                warning_count = 1,
                first_warning_at = NOW(),
                account_status = 'active',
                account_locked_until = NULL
            WHERE id = ?
        ");
        $statement->bind_param('i', $userId);
    } else {
        $statement = $connection->prepare("
            UPDATE users
            SET
                warning_count = ?,
                account_status = 'active',
                account_locked_until = NULL
            WHERE id = ?
        ");
        $statement->bind_param('ii', $strikeNumber, $userId);
    }

    $statement->execute();

    $remaining = reservation_security_warning_allowance($connection) - $strikeNumber;

    reservation_security_log_violation(
        $connection,
        $userId,
        reservation_security_ordinal($strikeNumber) . '_warning',
        sprintf(
            'A %s warning was issued after a reservation barcode expired without a booth scan. %d chance%s left before the account is locked.',
            reservation_security_ordinal($strikeNumber),
            $remaining,
            $remaining === 1 ? '' : 's'
        ),
        $reservationId,
        'system'
    );

    reservation_security_create_notification(
        $connection,
        'Reservation Warning',
        sprintf(
            'Warning %d of %d: your reservation expired because you did not arrive at the parking lot on time, and the slot was released. %s',
            $strikeNumber,
            reservation_security_warning_allowance($connection),
            $remaining === 0
                ? 'One more expired reservation will lock your account until a letter of appeal is approved.'
                : sprintf(
                    'You have %d more chance%s before your account is locked.',
                    $remaining,
                    $remaining === 1 ? '' : 's'
                )
        ),
        'Users',
        $userId,
        $reservationId
    );

    return [
        'warning_count' => $strikeNumber,
        'account_status' => 'active',
        'account_locked_until' => null
    ];
}

function reservation_security_expire_reservation_if_due(mysqli $connection, int $reservationId): ?array
{
    $reservation = reservation_security_fetch_reservation_state($connection, $reservationId, true);

    if (!$reservation) {
        return null;
    }

    $nowTimestamp = strtotime(reservation_security_database_now($connection));

    if (!reservation_security_reservation_is_due_for_expiration(
        $reservation,
        $nowTimestamp ?: time(),
        reservation_security_grace_minutes($connection)
    )) {
        return $reservation;
    }

    $statement = $connection->prepare("
        UPDATE reservations
        SET
            barcode_status = 'expired',
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND COALESCE(barcode_status, 'active') = 'active'
    ");
    $statement->bind_param('i', $reservationId);
    $statement->execute();

    if ($statement->affected_rows <= 0) {
        return reservation_security_fetch_reservation_state($connection, $reservationId, false);
    }

    $userId = (int) ($reservation['user_id'] ?? 0);

    if ($userId > 0) {
        reservation_security_log_violation(
            $connection,
            $userId,
            'barcode_expired',
            sprintf('Reservation barcode expired after %d minutes without a successful booth scan.', reservation_security_grace_minutes($connection)),
            $reservationId,
            'system'
        );

        reservation_security_create_notification(
            $connection,
            'Reservation Expired',
            sprintf('Your reservation expired because you did not arrive at the parking lot within %d minutes, ', reservation_security_grace_minutes($connection))
                . 'and the slot was released back to available.',
            'Users',
            $userId,
            $reservationId
        );

        reservation_security_apply_expiration_penalty($connection, $userId, $reservationId);
    }

    return reservation_security_fetch_reservation_state($connection, $reservationId, false);
}

function reservation_security_expire_due_reservations(mysqli $connection, ?int $userId = null, bool $force = false): array
{
    $cacheKey = $userId !== null && $userId > 0
        ? 'reservation-expire-user-' . $userId
        : 'reservation-expire-global';

    // The scheduled sweep passes $force: it is the authoritative run and must
    // not be skipped because a web request happened to touch the throttle file
    // a few seconds earlier.
    if (!$force && !reservation_security_should_run($cacheKey, 10)) {
        return [
            'expired_count' => 0,
            'reservation_ids' => []
        ];
    }

    $graceMinutes = reservation_security_grace_minutes($connection);

    // Candidates are anything past its arrival time plus the grace period.
    // Rows with no reserved time fall back to created_at, matching
    // reservation_security_deadline_timestamp().
    $sql = "
        SELECT r.id
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE COALESCE(r.barcode_status, 'active') = 'active'
          AND (pt.actual_time_in IS NULL OR pt.actual_time_in = '0000-00-00 00:00:00')
          AND NOW() >= DATE_ADD(
                COALESCE(
                    TIMESTAMP(r.reservation_date, COALESCE(r.reserved_time_in, '00:00:00')),
                    r.created_at
                ),
                INTERVAL ? MINUTE
              )
    ";

    $types = 'i';
    $params = [$graceMinutes];

    if ($userId !== null && $userId > 0) {
        $sql .= " AND r.user_id = ? ";
        $types .= 'i';
        $params[] = $userId;
    }

    $statement = $connection->prepare($sql);

    if ($types !== '') {
        $statement->bind_param($types, ...$params);
    }

    $statement->execute();
    $result = $statement->get_result();

    $expiredIds = [];

    while ($row = $result->fetch_assoc()) {
        $reservationId = (int) ($row['id'] ?? 0);

        if ($reservationId <= 0) {
            continue;
        }

        $expiredRecord = reservation_security_expire_reservation_if_due($connection, $reservationId);

        if ($expiredRecord && strtolower((string) ($expiredRecord['barcode_status'] ?? 'active')) === 'expired') {
            $expiredIds[] = $reservationId;
        }
    }

    return [
        'expired_count' => count($expiredIds),
        'reservation_ids' => $expiredIds
    ];
}
