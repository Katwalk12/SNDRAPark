<?php

declare(strict_types=1);

// Sets the Asia/Manila timezone. Without it these endpoints ran on the
// php.ini default while MySQL ran on system time, and every timestamp
// they wrote or compared was hours out.
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../utils/CorsHelper.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/system-settings.php';
require_once __DIR__ . '/../common/reservation-security.php';
require_once __DIR__ . '/../common/system-logs.php';
require_once __DIR__ . '/../parking-booth/common.php';

/**
 * When the garage is open. A reservation outside these hours could never be
 * honoured, so it is refused rather than stored.
 *
 * The dashboard enforces the same window client-side (PARKING_HOURS in
 * frontend/js/user-dashboard.js) and must be changed with these.
 */
const PARKING_OPENING_TIME = '08:00';
const PARKING_CLOSING_TIME = '22:00';

/**
 * Same-day booking closes an hour before the garage does, so a driver always
 * has room to arrive and be scanned before closing.
 */
const PARKING_SAME_DAY_CUTOFF = '21:00';

/**
 * Minutes since midnight for an HH:MM or HH:MM:SS value, or null if it is
 * neither.
 */
function parking_time_to_minutes(string $time): ?int
{
    if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
        return null;
    }

    $hours = (int) $matches[1];
    $minutes = (int) $matches[2];

    if ($hours > 23 || $minutes > 59) {
        return null;
    }

    return ($hours * 60) + $minutes;
}

/**
 * Human-readable opening hours, for error messages and the dashboard.
 */
function parking_hours_label(): string
{
    return sprintf(
        '%s to %s',
        date('g:i A', strtotime(PARKING_OPENING_TIME)),
        date('g:i A', strtotime(PARKING_CLOSING_TIME))
    );
}

function parking_time_label(string $time): string
{
    return date('g:i A', strtotime($time));
}

/**
 * The garage clock, read from the database so it matches the timestamps the
 * expiry sweep compares against rather than PHP's own timezone.
 */
function parking_server_now(mysqli $connection): array
{
    $row = $connection->query("SELECT CURDATE() AS today, DATE_FORMAT(NOW(), '%H:%i') AS clock")
        ->fetch_assoc() ?: [];

    return [
        'date' => (string) ($row['today'] ?? date('Y-m-d')),
        'time' => (string) ($row['clock'] ?? date('H:i'))
    ];
}

function parking_bootstrap_endpoint(string $allowedMethods = 'GET, POST'): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');

    if (ob_get_level() === 0) {
        ob_start();
    }

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    parking_send_common_headers($allowedMethods);
    parking_handle_preflight();
}

function parking_send_common_headers(string $allowedMethods = 'GET, POST'): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    CorsHelper::sendHeaders($allowedMethods, true, 'Content-Type, X-CSRF-Token');
}

function parking_handle_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        exit;
    }
}

function parking_request_data(): array
{
    $rawInput = file_get_contents('php://input') ?: '';
    $decoded = json_decode($rawInput, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    parse_str($rawInput, $parsed);
    return is_array($parsed) ? $parsed : [];
}

function parking_clean_text($value): string
{
    return trim((string) ($value ?? ''));
}

function parking_bool($value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

function parking_slot_sort_expression(string $column = 's.slot_code'): string
{
    $normalized = "UPPER(TRIM({$column}))";

    return "
        CASE
            WHEN {$normalized} REGEXP '^F[0-9]+-S[0-9]+$' THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX({$normalized}, '-S', 1), 'F', -1) AS UNSIGNED)
            WHEN {$normalized} REGEXP '^[A-Z]+[0-9]+$' THEN 0
            ELSE 999999
        END ASC,
        CASE
            WHEN {$normalized} REGEXP '^F[0-9]+-S[0-9]+$' THEN CAST(SUBSTRING_INDEX({$normalized}, '-S', -1) AS UNSIGNED)
            WHEN {$normalized} REGEXP '^[A-Z]+[0-9]+$' THEN CAST(REGEXP_REPLACE({$normalized}, '^[A-Z]+', '') AS UNSIGNED)
            ELSE 999999
        END ASC,
        {$normalized} ASC
    ";
}

function parking_floor_sort_order(string $floorName): int
{
    $normalized = strtoupper(trim($floorName));

    if ($normalized === 'LG' || $normalized === 'LOWER GROUND') {
        return 1;
    }

    if (preg_match('/^(\d+)/', $floorName, $matches) === 1) {
        return ((int) $matches[1]) + 1;
    }

    return 99;
}

function parking_runtime_cache_dir(): string
{
    $cacheDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

    if (!is_dir($cacheDirectory)) {
        mkdir($cacheDirectory, 0777, true);
    }

    return $cacheDirectory;
}

function parking_should_run_refresh(string $cacheKey, int $intervalSeconds): bool
{
    $cacheFile = parking_runtime_cache_dir() . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9._-]+/i', '-', $cacheKey) . '.json';
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

function parking_row_label_from_slot(string $slotCode): string
{
    if (preg_match('/^[A-Za-z]+/', $slotCode, $matches) === 1) {
        return strtoupper($matches[0]);
    }

    return 'ROW';
}

function parking_normalize_status(string $status): string
{
    $map = [
        'available' => 'Available',
        'reserved' => 'Reserved',
        'occupied' => 'Occupied',
        'inactive' => 'Inactive'
    ];

    $normalized = strtolower(trim($status));
    return $map[$normalized] ?? 'Available';
}

function parking_build_active_slot_subquery(): string
{
    return "
        SELECT
            f.id AS floor_id,
            rr.parking_slot,
            MAX(
                CASE
                    WHEN pt.actual_time_in IS NOT NULL
                     AND (pt.actual_time_out IS NULL OR pt.actual_time_out = '0000-00-00 00:00:00')
                    THEN 2
                    WHEN LOWER(COALESCE(rr.barcode_status, 'active')) <> 'active'
                    THEN 0
                    WHEN UPPER(COALESCE(rr.status, 'Reserved')) = 'RESERVED'
                     AND (pt.actual_time_in IS NULL OR pt.actual_time_in = '0000-00-00 00:00:00')
                    THEN 1
                    ELSE 0
                END
            ) AS active_rank
        FROM reservations rr
        LEFT JOIN parking_floors f ON f.floor_name = rr.parking_floor
        LEFT JOIN parking_transactions pt ON pt.reservation_id = rr.id
        GROUP BY f.id, rr.parking_slot
    ";
}

function parking_sync_slot_floor_links(mysqli $connection): void
{
    $connection->query("
        UPDATE parking_slots s
        INNER JOIN parking_floors f ON f.floor_name = s.floor_name
        SET s.floor_id = f.id
        WHERE s.floor_id IS NULL OR s.floor_id = 0
    ");

    $connection->query("
        UPDATE parking_slots s
        INNER JOIN parking_floors f ON f.id = s.floor_id
        SET s.floor_name = f.floor_name
        WHERE s.floor_name IS NULL OR s.floor_name = '' OR s.floor_name <> f.floor_name
    ");
}

function parking_sync_slot_statuses(mysqli $connection, bool $force = false): void
{
    if (!$force && !parking_should_run_refresh('parking-slot-status-sync', 5)) {
        return;
    }

    parking_sync_slot_floor_links($connection);
    $activeSlotSubquery = parking_build_active_slot_subquery();

    $connection->query("
        UPDATE parking_slots s
        LEFT JOIN parking_floors f ON f.id = s.floor_id
        LEFT JOIN ({$activeSlotSubquery}) active_slots
            ON active_slots.floor_id = f.id
           AND active_slots.parking_slot = s.slot_code
        SET
            s.row_label = CASE
                WHEN s.row_label IS NOT NULL AND s.row_label <> '' THEN s.row_label
                WHEN s.slot_code <> '' THEN UPPER(LEFT(s.slot_code, 1))
                ELSE 'ROW'
            END,
            s.status = CASE
                WHEN COALESCE(f.is_active, 0) = 0 OR s.is_active = 0 OR s.manual_status = 'Inactive' THEN 'Inactive'
                WHEN COALESCE(active_slots.active_rank, 0) = 2 THEN 'Occupied'
                WHEN COALESCE(active_slots.active_rank, 0) = 1 THEN 'Reserved'
                WHEN s.manual_status IN ('Available', 'Reserved', 'Occupied') THEN s.manual_status
                ELSE 'Available'
            END,
            s.updated_at = CURRENT_TIMESTAMP
    ");
}

function parking_refresh_floor_slot_state(mysqli $connection): void
{
    if (parking_should_run_refresh('parking-floor-slot-expiration-sync', 30)) {
        reservation_security_expire_due_reservations($connection);
    }

    parking_sync_slot_statuses($connection);
}

function parking_get_floors(mysqli $connection, bool $activeOnly = true): array
{
    parking_refresh_floor_slot_state($connection);

    $sql = "
        SELECT
            f.id,
            f.floor_name,
            COALESCE(NULLIF(f.floor_label, ''), f.floor_name) AS floor_label,
            f.is_active,
            f.unavailable_reason,
            f.sort_order,
            f.created_at,
            COUNT(s.id) AS slot_count,
            SUM(CASE WHEN s.status = 'Available' THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN s.status = 'Reserved' THEN 1 ELSE 0 END) AS reserved_count,
            SUM(CASE WHEN s.status = 'Occupied' THEN 1 ELSE 0 END) AS occupied_count
        FROM parking_floors f
        LEFT JOIN parking_slots s
            ON s.floor_id = f.id
           AND s.is_active = 1
        " . ($activeOnly ? "WHERE f.is_active = 1" : "") . "
        GROUP BY f.id, f.floor_name, f.floor_label, f.is_active, f.unavailable_reason, f.sort_order, f.created_at
        ORDER BY f.sort_order ASC, f.floor_name ASC
    ";

    $floors = [];
    $result = $connection->query($sql);

    while ($row = $result->fetch_assoc()) {
        $floors[] = [
            'id' => (int) $row['id'],
            'floor_name' => $row['floor_name'],
            'floor_label' => $row['floor_label'],
            'is_active' => (int) $row['is_active'],
            'unavailable_reason' => (string) ($row['unavailable_reason'] ?? ''),
            'sort_order' => (int) $row['sort_order'],
            'created_at' => $row['created_at'],
            'slot_count' => (int) ($row['slot_count'] ?? 0),
            'available_count' => (int) ($row['available_count'] ?? 0),
            'reserved_count' => (int) ($row['reserved_count'] ?? 0),
            'occupied_count' => (int) ($row['occupied_count'] ?? 0)
        ];
    }

    return $floors;
}

/**
 * Why a slot cannot be booked, in the words the driver should read.
 *
 * A slot's own reason wins; otherwise it inherits its floor's, so taking a
 * whole floor out of service explains every slot on it. Reserved and occupied
 * slots are ordinary traffic, not an outage, so they carry a plain note rather
 * than an admin reason.
 */
function parking_slot_unavailable_context(array $row, string $status): array
{
    $slotReason = trim((string) ($row['unavailable_reason'] ?? ''));
    $floorReason = trim((string) ($row['floor_unavailable_reason'] ?? ''));
    $floorIsActive = (int) ($row['floor_is_active'] ?? 1) === 1;
    $slotIsActive = (int) ($row['is_active'] ?? 1) === 1;

    if ($status === 'Reserved') {
        return [
            'unavailable_scope' => 'reserved',
            'unavailable_reason' => 'Someone already holds this slot. It frees up if they do not arrive in time.'
        ];
    }

    if ($status === 'Occupied') {
        return [
            'unavailable_scope' => 'occupied',
            'unavailable_reason' => 'A vehicle is parked here right now. It frees up once the driver checks out.'
        ];
    }

    if ($status !== 'Inactive') {
        return ['unavailable_scope' => '', 'unavailable_reason' => ''];
    }

    if (!$floorIsActive) {
        return [
            'unavailable_scope' => 'floor',
            'unavailable_reason' => $floorReason !== ''
                ? $floorReason
                : 'This floor is temporarily closed. No reason was recorded.'
        ];
    }

    if (!$slotIsActive || $slotReason !== '') {
        return [
            'unavailable_scope' => 'slot',
            'unavailable_reason' => $slotReason !== ''
                ? $slotReason
                : 'This slot is temporarily out of service. No reason was recorded.'
        ];
    }

    return [
        'unavailable_scope' => 'slot',
        'unavailable_reason' => 'This slot is temporarily out of service. No reason was recorded.'
    ];
}

function parking_get_slots(
    mysqli $connection,
    ?string $floorName = null,
    ?int $floorId = null,
    bool $activeFloorsOnly = false
): array {
    parking_refresh_floor_slot_state($connection);

    $sql = "
        SELECT
            s.id,
            f.id AS floor_id,
            f.floor_name,
            COALESCE(NULLIF(f.floor_label, ''), f.floor_name) AS floor_label,
            s.slot_code,
            COALESCE(NULLIF(s.row_label, ''), 'ROW') AS row_label,
            s.status,
            s.manual_status,
            s.is_active,
            f.is_active AS floor_is_active,
            s.unavailable_reason,
            f.unavailable_reason AS floor_unavailable_reason,
            s.created_at
        FROM parking_slots s
        INNER JOIN parking_floors f ON f.id = s.floor_id
        WHERE 1 = 1
    ";

    $types = '';
    $params = [];

    if ($floorId !== null && $floorId > 0) {
        $sql .= " AND f.id = ? ";
        $types .= 'i';
        $params[] = $floorId;
    } elseif ($floorName !== null && $floorName !== '') {
        $sql .= " AND f.floor_name = ? ";
        $types .= 's';
        $params[] = $floorName;
    }

    if ($activeFloorsOnly) {
        $sql .= " AND f.is_active = 1 AND s.is_active = 1 ";
    }

    $sql .= " ORDER BY f.sort_order ASC, " . parking_slot_sort_expression('s.slot_code');

    $statement = $connection->prepare($sql);

    if ($types !== '') {
        $statement->bind_param($types, ...$params);
    }

    $statement->execute();
    $result = $statement->get_result();

    $slots = [];

    while ($row = $result->fetch_assoc()) {
        $status = parking_normalize_status((string) ($row['status'] ?? 'Available'));
        $slots[] = array_merge([
            'id' => (int) $row['id'],
            'floor_id' => (int) $row['floor_id'],
            'floor_name' => $row['floor_name'],
            'floor_label' => $row['floor_label'],
            'slot_code' => $row['slot_code'],
            'row_label' => $row['row_label'],
            'status' => $status,
            'status_key' => strtolower($status),
            'manual_status' => $row['manual_status'],
            'is_active' => (int) $row['is_active'],
            'disabled' => in_array($status, ['Reserved', 'Occupied', 'Inactive'], true),
            'created_at' => $row['created_at']
        ], parking_slot_unavailable_context($row, $status));
    }

    return $slots;
}

function parking_get_slot_record_for_update(mysqli $connection, string $floorName, string $slotCode): ?array
{
    $activeSlotSubquery = parking_build_active_slot_subquery();

    $sql = "
        SELECT
            s.id,
            f.id AS floor_id,
            f.floor_name,
            COALESCE(NULLIF(f.floor_label, ''), f.floor_name) AS floor_label,
            f.is_active AS floor_is_active,
            s.slot_code,
            COALESCE(NULLIF(s.row_label, ''), 'ROW') AS row_label,
            s.is_active,
            s.manual_status,
            s.status,
            CASE
                WHEN COALESCE(f.is_active, 0) = 0 OR s.is_active = 0 OR s.manual_status = 'Inactive' THEN 'Inactive'
                WHEN COALESCE(active_slots.active_rank, 0) = 2 THEN 'Occupied'
                WHEN COALESCE(active_slots.active_rank, 0) = 1 THEN 'Reserved'
                WHEN s.manual_status IN ('Available', 'Reserved', 'Occupied') THEN s.manual_status
                ELSE 'Available'
            END AS live_status
        FROM parking_slots s
        INNER JOIN parking_floors f ON f.id = s.floor_id
        LEFT JOIN ({$activeSlotSubquery}) active_slots
            ON active_slots.floor_id = f.id
           AND active_slots.parking_slot = s.slot_code
        WHERE f.floor_name = ?
          AND s.slot_code = ?
        LIMIT 1
        FOR UPDATE
    ";

    $statement = $connection->prepare($sql);
    $statement->bind_param('ss', $floorName, $slotCode);
    $statement->execute();
    $result = $statement->get_result();

    $row = $result->fetch_assoc();
    if (!$row) {
        return null;
    }

    $row['live_status'] = parking_normalize_status((string) ($row['live_status'] ?? 'Available'));
    return $row;
}

function parking_generate_barcode(string $floorName, string $slotCode): string
{
    $compactFloor = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $floorName));
    $compactSlot = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $slotCode));
    $seed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    return booth_normalize_barcode("SP-{$compactFloor}-{$compactSlot}-{$seed}");
}

/**
 * Mint a barcode that no reservation is already holding.
 *
 * parking_generate_barcode() seeds from random_bytes so a clash is remote, but
 * the column carries a UNIQUE index now and a caller deserves a clean error
 * rather than a duplicate-key exception surfacing as a 500.
 */
function parking_generate_unique_barcode(mysqli $connection, string $floorName, string $slotCode): string
{
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $candidate = parking_generate_barcode($floorName, $slotCode);
        $lookup = booth_lookup_barcode($candidate);

        if ($lookup === '') {
            continue;
        }

        $statement = $connection->prepare("SELECT id FROM reservations WHERE barcode_lookup = ? LIMIT 1");
        $statement->bind_param('s', $lookup);
        $statement->execute();
        $exists = (bool) $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$exists) {
            return $candidate;
        }
    }

    throw new RuntimeException('Failed to generate a unique reservation barcode.', 500);
}

function parking_generate_short_code(mysqli $connection, int $length = 6): string
{
    $length = max(4, min(8, $length));
    $attempts = 0;

    do {
        // Generate a numeric short code of the requested length
        $max = (int) pow(10, $length) - 1;
        $min = (int) pow(10, $length - 1);
        try {
            $num = random_int($min, $max);
        } catch (Throwable $e) {
            $num = mt_rand($min, $max);
        }

        $code = (string) $num;
        $lookup = booth_lookup_barcode($code);

        $stmt = $connection->prepare("SELECT id FROM reservations WHERE short_code_lookup = ? LIMIT 1");
        $stmt->bind_param('s', $lookup);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $attempts++;
    } while ($exists && $attempts < 12);

    if ($exists) {
        throw new RuntimeException('Failed to generate unique short barcode code.', 500);
    }

    return $code;
}

/**
 * A driver may hold one reservation at a time.
 *
 * The hold clears the moment the booth scans the barcode (which stamps
 * actual_time_in), when the driver cancels, or when the reservation expires
 * for a no-show. Anything still Reserved, still active and never scanned is
 * therefore an outstanding hold that blocks a second slot.
 */
function parking_find_active_unscanned_reservation(mysqli $connection, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $statement = $connection->prepare("
        SELECT
            r.id,
            r.barcode_value,
            r.short_code,
            r.parking_floor,
            r.parking_slot,
            r.reservation_date,
            r.reserved_time_in
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE r.user_id = ?
          AND LOWER(COALESCE(r.barcode_status, 'active')) = 'active'
          AND UPPER(COALESCE(r.status, 'Reserved')) = 'RESERVED'
          AND (pt.actual_time_in IS NULL OR pt.actual_time_in = '0000-00-00 00:00:00')
        ORDER BY r.id DESC
        LIMIT 1
    ");
    $statement->bind_param('i', $userId);
    $statement->execute();

    return $statement->get_result()->fetch_assoc() ?: null;
}

function parking_assert_single_active_reservation(mysqli $connection, int $userId): void
{
    $existing = parking_find_active_unscanned_reservation($connection, $userId);

    if (!$existing) {
        return;
    }

    throw new RuntimeException(
        sprintf(
            'You already have an active reservation for %s %s. Have it scanned at the parking booth, or cancel it, before reserving another slot.',
            (string) ($existing['parking_floor'] ?? ''),
            (string) ($existing['parking_slot'] ?? '')
        ),
        409
    );
}

function parking_create_reservation(mysqli $connection, array $payload): array
{
    $userId = (int) ($payload['user_id'] ?? 0);
    $parkingFloor = parking_clean_text($payload['parking_floor'] ?? '');
    $parkingSlot = strtoupper(parking_clean_text($payload['parking_slot'] ?? ''));
    $fullName = parking_clean_text($payload['full_name'] ?? '');
    $email = parking_clean_text($payload['email'] ?? '');
    $vehicleId = (int) ($payload['vehicle_id'] ?? 0);
    $reservationDate = parking_clean_text($payload['reservation_date'] ?? '');
    $reservedTimeIn = parking_clean_text($payload['reserved_time_in'] ?? '');
    $reservationFee = system_settings_base_rate($connection);

    if ($userId <= 0 || $parkingFloor === '' || $parkingSlot === '' || $reservationDate === '' || $reservedTimeIn === '') {
        throw new RuntimeException('Incomplete reservation details.', 400);
    }

    reservation_security_expire_due_reservations($connection, $userId);
    reservation_security_assert_user_can_reserve($connection, $userId);
    parking_assert_single_active_reservation($connection, $userId);

    if ($vehicleId <= 0) {
        throw new RuntimeException('Please add and select a registered vehicle first.', 422);
    }

    $vehicleStatement = $connection->prepare("
        SELECT vehicle_id
        FROM vehicles
        WHERE vehicle_id = ?
          AND user_id = ?
        LIMIT 1
    ");
    $vehicleStatement->bind_param('ii', $vehicleId, $userId);
    $vehicleStatement->execute();

    if (!$vehicleStatement->get_result()->fetch_assoc()) {
        throw new RuntimeException('Selected vehicle was not found on your account.', 422);
    }

    if ($fullName === '' || $email === '') {
        $userStatement = $connection->prepare("
            SELECT full_name, email
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $userStatement->bind_param('i', $userId);
        $userStatement->execute();
        $userResult = $userStatement->get_result()->fetch_assoc() ?: [];

        $fullName = $fullName !== '' ? $fullName : parking_clean_text($userResult['full_name'] ?? '');
        $email = $email !== '' ? $email : parking_clean_text($userResult['email'] ?? '');
    }

    if ($fullName === '' || $email === '') {
        throw new RuntimeException('Full name and email are required.', 422);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Please provide a valid email address.', 422);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
        throw new RuntimeException('Please provide a valid reservation date.', 422);
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $reservedTimeIn) && !preg_match('/^\d{2}:\d{2}:\d{2}$/', $reservedTimeIn)) {
        throw new RuntimeException('Please provide a valid reservation time.', 422);
    }

    $reservedMinutes = parking_time_to_minutes($reservedTimeIn);
    $openingMinutes = parking_time_to_minutes(PARKING_OPENING_TIME);
    $closingMinutes = parking_time_to_minutes(PARKING_CLOSING_TIME);

    if ($reservedMinutes === null) {
        throw new RuntimeException('Please provide a valid reservation time.', 422);
    }

    if ($reservedMinutes < $openingMinutes || $reservedMinutes > $closingMinutes) {
        throw new RuntimeException(
            sprintf('Reservations are only accepted from %s.', parking_hours_label()),
            422
        );
    }

    $serverNow = parking_server_now($connection);
    $todayDate = $serverNow['date'];
    $nowMinutes = parking_time_to_minutes($serverNow['time']) ?? 0;
    $cutoffMinutes = parking_time_to_minutes(PARKING_SAME_DAY_CUTOFF) ?? 0;

    if ($reservationDate < $todayDate) {
        throw new RuntimeException('That date has already passed. Please choose today or a later date.', 422);
    }

    if ($reservationDate === $todayDate) {
        // Past the cutoff there is no longer enough of the day left to arrive
        // and be scanned, so today closes and only later dates remain.
        if ($nowMinutes > $cutoffMinutes) {
            throw new RuntimeException(
                sprintf(
                    'Reservations for today closed at %s. Please choose another day.',
                    parking_time_label(PARKING_SAME_DAY_CUTOFF)
                ),
                422
            );
        }

        // A time already gone would expire as a no-show within 30 minutes and
        // cost the driver a warning they had no way to avoid.
        if ($reservedMinutes <= $nowMinutes) {
            throw new RuntimeException(
                sprintf(
                    'That arrival time has already passed. Please choose a time after %s today.',
                    parking_time_label($serverNow['time'])
                ),
                422
            );
        }
    }

    // Both codes are minted here and never taken from the request. The browser
    // used to send the barcode it had drawn, so a caller could pick any string
    // -- including one already issued to somebody else. The booth resolves a
    // scan with LIMIT 1, so a duplicate would have timed in, and billed, the
    // wrong driver.
    $barcodeValue = parking_generate_unique_barcode($connection, $parkingFloor, $parkingSlot);
    $barcodeLookup = booth_lookup_barcode($barcodeValue);

    if ($barcodeLookup === '') {
        throw new RuntimeException('Failed to generate a valid barcode value.', 500);
    }

    $shortCode = parking_generate_short_code($connection, 6);
    $shortCodeLookup = booth_lookup_barcode($shortCode);

    $connection->begin_transaction();

    try {
        $slot = parking_get_slot_record_for_update($connection, $parkingFloor, $parkingSlot);

        if (!$slot) {
            throw new RuntimeException('Parking slot not found.', 404);
        }

        if (($slot['live_status'] ?? 'Available') !== 'Available') {
            throw new RuntimeException('This parking slot is no longer available.', 409);
        }

        $userStatement = $connection->prepare("
            INSERT INTO users (id, full_name, email, created_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name),
                email = VALUES(email)
        ");
        $userStatement->bind_param('iss', $userId, $fullName, $email);
        $userStatement->execute();

        parking_assert_single_active_reservation($connection, $userId);

        $reservationStatement = $connection->prepare("\n            INSERT INTO reservations (\n                user_id,\n                vehicle_id,\n                barcode_value,\n                barcode_lookup,\n                short_code,\n                short_code_lookup,\n                barcode_status,\n                full_name,\n                email,\n                parking_floor,\n                parking_slot,\n                reservation_date,\n                reserved_time_in,\n                reservation_fee,\n                status,\n                created_at,\n                updated_at\n            )\n            VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, 'Reserved', NOW(), NOW())\n        ");
        $reservationStatement->bind_param(
            'iissssssssssd',
            $userId,
            $vehicleId,
            $barcodeValue,
            $barcodeLookup,
            $shortCode,
            $shortCodeLookup,
            $fullName,
            $email,
            $parkingFloor,
            $parkingSlot,
            $reservationDate,
            $reservedTimeIn,
            $reservationFee
        );
        $reservationStatement->execute();

        $reservationId = (int) $connection->insert_id;

        booth_upsert_transaction(
            $connection,
            $reservationId,
            null,
            null,
            0.00,
            0.00,
            0.00,
            'Reserved',
            'Reserved',
            null
        );

        $slotStatusStatement = $connection->prepare("
            UPDATE parking_slots
            SET status = 'Reserved', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $slotStatusStatement->bind_param('i', $slot['id']);
        $slotStatusStatement->execute();

        $record = booth_find_transaction_by_reservation_id($connection, $reservationId);
        $connection->commit();

        system_logs_write($connection, [
            'user_id' => $userId,
            'vehicle_id' => $vehicleId,
            'actor_role' => 'user',
            'actor_name' => $fullName,
            'action_type' => 'USER_RESERVATION_CREATED',
            'description' => "User reserved slot {$parkingSlot} on {$parkingFloor}.",
            'related_barcode' => $barcodeValue,
            'related_floor' => $parkingFloor,
            'related_slot' => $parkingSlot,
            'amount' => $reservationFee,
            'status' => 'Reserved'
        ]);

        try {
            parking_sync_slot_statuses($connection, true);
        } catch (Throwable $syncException) {
            booth_log('parking-sync-after-reservation-failed', [
                'reservation_id' => $reservationId,
                'error' => $syncException->getMessage()
            ]);
        }

        return booth_format_transaction($record ?: [
            'reservation_id' => $reservationId,
            'user_id' => $userId,
            'full_name' => $fullName,
            'email' => $email,
            'barcode_value' => $barcodeValue,
            'parking_floor' => $parkingFloor,
            'parking_slot' => $parkingSlot,
            'reservation_date' => $reservationDate,
            'reserved_time_in' => $reservedTimeIn,
            'reservation_fee' => $reservationFee,
            'reservation_status' => 'Reserved',
            'payment_status' => 'Reserved',
            'booth_status' => 'Reserved',
            'last_updated_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function parking_get_live_reservations(mysqli $connection, int $limit = 25): array
{
    reservation_security_expire_due_reservations($connection);
    parking_sync_slot_statuses($connection);

    $limit = max(1, min($limit, 100));
    $sql = "
        SELECT
            r.id,
            r.user_id,
            r.vehicle_id,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(NULLIF(TRIM(r.email), ''), u.email, '--') AS email,
            r.barcode_value,
            r.short_code,
            r.parking_floor,
            r.parking_slot,
            r.reservation_date,
            r.reserved_time_in,
            CASE
                WHEN UPPER(COALESCE(r.status, 'Reserved')) = 'CANCELLED' THEN 'Cancelled'
                WHEN LOWER(COALESCE(r.barcode_status, 'active')) = 'expired' THEN 'Expired'
                ELSE COALESCE(pt.booth_status, r.status, 'Reserved')
            END AS status,
            COALESCE(r.barcode_status, 'active') AS barcode_status,
            r.created_at,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS updated_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT ?
    ";

    $statement = $connection->prepare($sql);
    $statement->bind_param('i', $limit);
    $statement->execute();
    $result = $statement->get_result();

    $reservations = [];

    while ($row = $result->fetch_assoc()) {
        $reservations[] = [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
        'barcode' => $row['barcode_value'],
        'barcode_value' => $row['barcode_value'],
        'short_code' => $row['short_code'] ?? null,
            'floor' => $row['parking_floor'],
            'parking_floor' => $row['parking_floor'],
            'slot' => $row['parking_slot'],
            'parking_slot' => $row['parking_slot'],
            'reservation_date' => $row['reservation_date'],
            'reserved_time_in' => $row['reserved_time_in'],
            'status' => $row['status'],
            'barcode_status' => $row['barcode_status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    return $reservations;
}

function parking_get_user_reservations(mysqli $connection, int $userId): array
{
    reservation_security_expire_due_reservations($connection, $userId);
    reservation_security_sync_user_account($connection, $userId);

    $sql = "
        SELECT
            r.id,
            r.user_id,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(NULLIF(TRIM(r.email), ''), u.email, '--') AS email,
            r.barcode_value,
            r.parking_floor,
            r.parking_slot,
            r.reservation_date,
            r.reserved_time_in,
            r.reserved_time_out,
            r.reservation_fee,
            r.status AS reservation_status,
            COALESCE(pt.payment_status, 'Reserved') AS payment_status,
            CASE
                WHEN UPPER(COALESCE(r.status, 'Reserved')) = 'CANCELLED' THEN 'Cancelled'
                WHEN LOWER(COALESCE(r.barcode_status, 'active')) = 'expired' THEN 'Expired'
                ELSE COALESCE(pt.booth_status, r.status, 'Reserved')
            END AS booth_status,
            COALESCE(r.barcode_status, 'active') AS barcode_status,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS updated_at,
            v.vehicle_type,
            v.plate_number,
            v.brand AS vehicle_brand,
            v.model AS vehicle_model,
            v.color AS vehicle_color,
            r.created_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN vehicles v ON v.vehicle_id = r.vehicle_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC, r.id DESC
    ";

    $statement = $connection->prepare($sql);
    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();

    $reservations = [];

    while ($row = $result->fetch_assoc()) {
        $reservations[] = [
            'reservationId' => (int) $row['id'],
            'userId' => (int) $row['user_id'],
            'vehicleId' => (int) ($row['vehicle_id'] ?? 0),
            'fullName' => $row['full_name'],
            'email' => $row['email'],
            'barcode' => $row['barcode_value'],
            'barcodeValue' => $row['barcode_value'],
            'floor' => $row['parking_floor'],
            'slot' => $row['parking_slot'],
            'reservationDate' => $row['reservation_date'],
            'reservedTimeIn' => $row['reserved_time_in'],
            'reservedTimeOut' => $row['reserved_time_out'],
            'reservationFee' => (float) ($row['reservation_fee'] ?? 0),
            'paymentStatus' => $row['payment_status'],
            'boothStatus' => $row['booth_status'],
            'barcodeStatus' => $row['barcode_status'],
            'status' => $row['reservation_status'],
            'reservationStatus' => $row['reservation_status'],
            'vehicleType' => $row['vehicle_type'] ?? null,
            'plateNumber' => $row['plate_number'] ?? null,
            'vehicleBrand' => $row['vehicle_brand'] ?? null,
            'vehicleModel' => $row['vehicle_model'] ?? null,
            'vehicleColor' => $row['vehicle_color'] ?? null,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at']
        ];
    }

    return $reservations;
}

function parking_cancel_reservation(mysqli $connection, int $userId, int $reservationId): array
{
    if ($userId <= 0) {
        throw new RuntimeException('No active user session found.', 401);
    }

    if ($reservationId <= 0) {
        throw new RuntimeException('Please provide a valid reservation to cancel.', 422);
    }

    reservation_security_expire_due_reservations($connection, $userId);
    reservation_security_sync_user_account($connection, $userId);

    $connection->begin_transaction();

    try {
        $statement = $connection->prepare("
            SELECT
                r.id,
                r.user_id,
                COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
                COALESCE(NULLIF(TRIM(r.email), ''), u.email, '--') AS email,
                r.barcode_value,
                COALESCE(r.barcode_status, 'active') AS barcode_status,
                COALESCE(r.parking_floor, '') AS parking_floor,
                COALESCE(r.parking_slot, '') AS parking_slot,
                r.reservation_date,
                r.reserved_time_in,
                r.reserved_time_out,
                COALESCE(r.reservation_fee, 0) AS reservation_fee,
                COALESCE(r.status, 'Reserved') AS reservation_status,
                COALESCE(pt.payment_status, 'Reserved') AS payment_status,
                COALESCE(pt.booth_status, 'Reserved') AS booth_status,
                pt.actual_time_in,
                pt.actual_time_out,
                r.created_at
            FROM reservations r
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
            WHERE r.id = ?
              AND r.user_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $statement->bind_param('ii', $reservationId, $userId);
        $statement->execute();
        $reservation = $statement->get_result()->fetch_assoc() ?: null;

        if (!$reservation) {
            throw new RuntimeException('Reservation not found or you do not have permission to cancel it.', 404);
        }

        $reservationStatus = strtoupper(trim((string) ($reservation['reservation_status'] ?? 'Reserved')));
        $boothStatus = strtoupper(trim((string) ($reservation['booth_status'] ?? 'Reserved')));
        $paymentStatus = strtoupper(trim((string) ($reservation['payment_status'] ?? 'Reserved')));
        $actualTimeIn = trim((string) ($reservation['actual_time_in'] ?? ''));

        if ($reservationStatus === 'CANCELLED') {
            throw new RuntimeException('This reservation is already cancelled.', 409);
        }

        $nonCancellableStatuses = ['PARKED', 'EXITED', 'UNPAID', 'PAID', 'COMPLETED'];
        if (
            ($actualTimeIn !== '' && $actualTimeIn !== '0000-00-00 00:00:00')
            || in_array($reservationStatus, $nonCancellableStatuses, true)
            || in_array($boothStatus, $nonCancellableStatuses, true)
            || $paymentStatus === 'PAID'
        ) {
            throw new RuntimeException('This reservation can no longer be cancelled.', 409);
        }

        $slot = null;
        if ($reservation['parking_floor'] !== '' && $reservation['parking_slot'] !== '') {
            $slot = parking_get_slot_record_for_update(
                $connection,
                (string) $reservation['parking_floor'],
                (string) $reservation['parking_slot']
            );
        }

        $reservationUpdate = $connection->prepare("
            UPDATE reservations
            SET status = 'Cancelled',
                barcode_status = 'cancelled',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND user_id = ?
        ");
        $reservationUpdate->bind_param('ii', $reservationId, $userId);
        $reservationUpdate->execute();

        if ($slot && isset($slot['id'])) {
            $slotStatusStatement = $connection->prepare("
                UPDATE parking_slots
                SET status = 'Available', updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $slotId = (int) $slot['id'];
            $slotStatusStatement->bind_param('i', $slotId);
            $slotStatusStatement->execute();
        }

        $connection->commit();

        $actorName = parking_clean_text($reservation['full_name'] ?? '') !== ''
            ? parking_clean_text($reservation['full_name'] ?? '')
            : (parking_clean_text($reservation['email'] ?? '') !== '' ? parking_clean_text($reservation['email'] ?? '') : 'Reservation Holder');

        system_logs_write($connection, [
            'user_id' => $userId,
            'actor_role' => 'user',
            'actor_name' => $actorName,
            'action_type' => 'USER_RESERVATION_CANCELLED',
            'description' => 'User cancelled reservation for slot '
                . (string) ($reservation['parking_slot'] ?? '--')
                . ' on '
                . (string) ($reservation['parking_floor'] ?? '--')
                . '.',
            'related_barcode' => (string) ($reservation['barcode_value'] ?? ''),
            'related_floor' => (string) ($reservation['parking_floor'] ?? ''),
            'related_slot' => (string) ($reservation['parking_slot'] ?? ''),
            'amount' => (float) ($reservation['reservation_fee'] ?? 0),
            'status' => 'Cancelled'
        ]);

        reservation_security_create_notification(
            $connection,
            'Reservation Cancelled',
            'Your reservation for slot '
            . (string) ($reservation['parking_slot'] ?? '--')
            . ' on '
            . (string) ($reservation['parking_floor'] ?? '--')
            . ' was cancelled successfully.',
            'Users',
            $userId,
            $reservationId
        );

        try {
            parking_sync_slot_statuses($connection, true);
        } catch (Throwable $syncException) {
            booth_log('parking-sync-after-cancel-failed', [
                'reservation_id' => $reservationId,
                'error' => $syncException->getMessage()
            ]);
        }

        return [
            'reservationId' => $reservationId,
            'userId' => $userId,
            'fullName' => $reservation['full_name'],
            'email' => $reservation['email'],
            'barcode' => $reservation['barcode_value'],
            'barcodeValue' => $reservation['barcode_value'],
            'floor' => $reservation['parking_floor'],
            'slot' => $reservation['parking_slot'],
            'reservationDate' => $reservation['reservation_date'],
            'reservedTimeIn' => $reservation['reserved_time_in'],
            'reservedTimeOut' => $reservation['reserved_time_out'],
            'reservationFee' => (float) ($reservation['reservation_fee'] ?? 0),
            'paymentStatus' => $reservation['payment_status'],
            'boothStatus' => 'Cancelled',
            'barcodeStatus' => 'cancelled',
            'status' => 'Cancelled',
            'reservationStatus' => 'Cancelled',
            'createdAt' => $reservation['created_at'],
            'updatedAt' => date('Y-m-d H:i:s')
        ];
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function parking_add_floor(mysqli $connection, string $floorName, ?string $floorLabel = null): array
{
    $floorName = parking_clean_text($floorName);
    $floorLabel = parking_clean_text($floorLabel ?: $floorName);

    if ($floorName === '') {
        throw new RuntimeException('Floor name is required.', 422);
    }

    $sortOrder = parking_floor_sort_order($floorName);
    $statement = $connection->prepare("
        INSERT INTO parking_floors (floor_name, floor_label, is_active, sort_order)
        VALUES (?, ?, 1, ?)
    ");
    $statement->bind_param('ssi', $floorName, $floorLabel, $sortOrder);
    $statement->execute();

    return [
        'id' => (int) $connection->insert_id,
        'floor_name' => $floorName,
        'floor_label' => $floorLabel,
        'is_active' => 1,
        'sort_order' => $sortOrder
    ];
}
