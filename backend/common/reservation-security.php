<?php

declare(strict_types=1);

require_once __DIR__ . '/system-logs.php';

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
            'The active warning window expired after 24 hours with no second barcode expiration.',
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
        && ($nowTimestamp - $firstWarningTimestamp) >= 86400
    ) {
        reservation_security_reset_warning_state($connection, $userId);
        return reservation_security_get_user_state($connection, $userId, $forUpdate);
    }

    return $user;
}

function reservation_security_build_login_lock_message(?string $lockedUntil): string
{
    return 'Your account is temporarily locked until '
        . reservation_security_format_datetime_for_message($lockedUntil)
        . ' due to repeated expired reservations.';
}

function reservation_security_build_reservation_lock_message(?string $lockedUntil): string
{
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
            reservation_security_build_login_lock_message($user['account_locked_until'] ?? null),
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
            reservation_security_build_reservation_lock_message($user['account_locked_until'] ?? null),
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

function reservation_security_reservation_is_due_for_expiration(array $reservation, ?int $nowTimestamp = null): bool
{
    $barcodeStatus = strtolower(trim((string) ($reservation['barcode_status'] ?? 'active')));

    if ($barcodeStatus !== 'active') {
        return false;
    }

    if (!empty($reservation['actual_time_in']) || !empty($reservation['actual_time_out'])) {
        return false;
    }

    $createdAtTimestamp = !empty($reservation['created_at']) ? strtotime((string) $reservation['created_at']) : false;

    if ($createdAtTimestamp === false) {
        return false;
    }

    $nowTimestamp = $nowTimestamp ?? strtotime('now');
    return ($nowTimestamp - $createdAtTimestamp) >= 1800;
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
    $withinWarningWindow = $warningCount >= 1
        && $firstWarningTimestamp !== false
        && ($nowTimestamp - $firstWarningTimestamp) < 86400;

    if ($withinWarningWindow) {
        $statement = $connection->prepare("
            UPDATE users
            SET
                warning_count = 2,
                account_status = 'locked',
                account_locked_until = DATE_ADD(NOW(), INTERVAL 6 DAY)
            WHERE id = ?
        ");
        $statement->bind_param('i', $userId);
        $statement->execute();

        $lockedUntilStatement = $connection->prepare("
            SELECT DATE_FORMAT(account_locked_until, '%Y-%m-%d %H:%i:%s') AS account_locked_until
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $lockedUntilStatement->bind_param('i', $userId);
        $lockedUntilStatement->execute();
        $lockedUntilRow = $lockedUntilStatement->get_result()->fetch_assoc() ?: [];
        $lockedUntil = $lockedUntilRow['account_locked_until'] ?? null;

        reservation_security_log_violation(
            $connection,
            $userId,
            'second_warning',
            'A second reservation barcode expired within 24 hours of the first warning.',
            $reservationId,
            'system'
        );
        reservation_security_log_violation(
            $connection,
            $userId,
            'account_locked',
            'The user account was locked for 6 days due to repeated reservation barcode expiration.',
            $reservationId,
            'system'
        );

        reservation_security_create_notification(
            $connection,
            'Account Locked',
            'Your account has been temporarily locked for 6 days due to repeated reservation barcode expiration.',
            'Users',
            $userId,
            $reservationId
        );

        return [
            'warning_count' => 2,
            'account_status' => 'locked',
            'account_locked_until' => $lockedUntil
        ];
    }

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
    $statement->execute();

    reservation_security_log_violation(
        $connection,
        $userId,
        'first_warning',
        'A first warning was issued after the reservation barcode expired without being scanned within 30 minutes.',
        $reservationId,
        'system'
    );

    reservation_security_create_notification(
        $connection,
        'Reservation Warning',
        'Warning: Your reservation barcode expired because it was not scanned within 30 minutes. If this happens again within 24 hours, your account will be locked for 6 days.',
        'Users',
        $userId,
        $reservationId
    );

    return [
        'warning_count' => 1,
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

    if (!reservation_security_reservation_is_due_for_expiration($reservation, $nowTimestamp ?: time())) {
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
            'Reservation barcode expired after 30 minutes without a successful booth scan.',
            $reservationId,
            'system'
        );

        reservation_security_create_notification(
            $connection,
            'Reservation Expired',
            'Your reservation barcode expired because it was not scanned within 30 minutes.',
            'Users',
            $userId,
            $reservationId
        );

        reservation_security_apply_expiration_penalty($connection, $userId, $reservationId);
    }

    return reservation_security_fetch_reservation_state($connection, $reservationId, false);
}

function reservation_security_expire_due_reservations(mysqli $connection, ?int $userId = null): array
{
    $cacheKey = $userId !== null && $userId > 0
        ? 'reservation-expire-user-' . $userId
        : 'reservation-expire-global';

    if (!reservation_security_should_run($cacheKey, 10)) {
        return [
            'expired_count' => 0,
            'reservation_ids' => []
        ];
    }

    $sql = "
        SELECT r.id
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE COALESCE(r.barcode_status, 'active') = 'active'
          AND (pt.actual_time_in IS NULL OR pt.actual_time_in = '0000-00-00 00:00:00')
          AND TIMESTAMPDIFF(MINUTE, r.created_at, NOW()) >= 30
    ";

    $types = '';
    $params = [];

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
