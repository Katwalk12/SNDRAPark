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
require_once __DIR__ . '/../middleware/BoothAuthMiddleware.php';
require_once __DIR__ . '/../middleware/ErrorMiddleware.php';
require_once __DIR__ . '/../middleware/AuditLogger.php';

function booth_bootstrap_endpoint(string $allowedMethods, ?string $requiredPermission = null): array
{
    // Set up error handling
    ErrorMiddleware::setupGlobalErrorHandling();

    // Start output buffering
    if (ob_get_level() === 0) {
        ob_start();
    }

    // Send common headers
    booth_send_common_headers($allowedMethods);

    // Handle preflight requests
    booth_handle_preflight();

    // Authenticate booth request
    try {
        $boothUser = BoothAuthMiddleware::authenticate($requiredPermission);
        return $boothUser;
    } catch (Exception $e) {
        // Log the authentication failure
        AuditLogger::log('booth_access_denied', 'booth_endpoint', 'warning',
            "Booth endpoint access denied: {$e->getMessage()}");

        // Return error response
        http_response_code($e->getCode() ?: 401);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'timestamp' => date('c')
        ]);
        exit;
    }
}

function booth_send_common_headers(string $allowedMethods): void
{
    header('Content-Type: application/json');
    CorsHelper::sendHeaders(
        $allowedMethods,
        true,
        'Content-Type, X-Booth-Token, X-Booth-API-Key, X-CSRF-Token'
    );
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
}

function booth_handle_preflight(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }
}

function booth_request_data(): array
{
    if (isset($GLOBALS['booth_request_data_override']) && is_array($GLOBALS['booth_request_data_override'])) {
        return $GLOBALS['booth_request_data_override'];
    }

    $rawInput = file_get_contents('php://input');
    $decoded = json_decode($rawInput, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    return is_array($_POST) ? $_POST : [];
}

function booth_normalize_barcode(string $value): string
{
    $barcode = trim($value);
    $barcode = preg_replace('/^\](?:C1|c1|E0|e0|d2|D2)/', '', $barcode);
    $barcode = preg_replace('/[\x00-\x1F\x7F]+/u', '', $barcode);
    $barcode = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $barcode);
    $barcode = preg_replace('/\s+/u', '', $barcode);
    $barcode = str_replace(["\u{2013}", "\u{2014}"], '-', $barcode);

    return strtoupper($barcode);
}

function booth_lookup_barcode(string $value): string
{
    return preg_replace('/[^A-Z0-9]/', '', booth_normalize_barcode($value));
}

function booth_get_database_now(mysqli $connection): string
{
    $result = $connection->query("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') AS current_datetime");
    $row = $result->fetch_assoc();
    return $row['current_datetime'];
}

/** True when a timestamp falls inside the configured night band, which may wrap midnight. */
function booth_time_is_in_night_band(string $timestamp, string $bandStart, string $bandEnd): bool
{
    $minuteOf = static function (string $value): ?int {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 60 + (int) $matches[2];
    };

    $start = $minuteOf($bandStart);
    $end = $minuteOf($bandEnd);
    $moment = $minuteOf(date('H:i', strtotime($timestamp) ?: time()));

    if ($start === null || $end === null || $moment === null || $start === $end) {
        return false;
    }

    // A band such as 22:00 -> 06:00 wraps past midnight.
    return $start < $end
        ? ($moment >= $start && $moment < $end)
        : ($moment >= $start || $moment < $end);
}

/**
 * Price one stay.
 *
 * Every vehicle used to pay the same flat rate. The fee now runs
 * base -> overtime -> vehicle class -> night band -> statutory discount, with
 * each step read from the system settings so the owner can change any of them
 * without a deploy. $context accepts `vehicle_type` and `discount_type`
 * ('Senior' or 'PWD' for the discount both RA 9994 and RA 10754 require).
 */
function booth_calculate_payment(
    mysqli $connection,
    string $actualTimeIn,
    string $actualTimeOut,
    float $reservationFee,
    array $context = []
): array {
    $start = new DateTime($actualTimeIn);
    $end = new DateTime($actualTimeOut);
    $secondsStayed = max(0, $end->getTimestamp() - $start->getTimestamp());
    $hoursStayed = max(1, (int) ceil($secondsStayed / 3600));

    $baseAmount = system_settings_base_rate($connection);
    $extraHourlyRate = system_settings_extra_hourly_rate($connection);
    $includedHours = (int) ceil((float) system_settings_value('base_included_hours', $connection));
    $multiplier = system_settings_vehicle_multiplier((string) ($context['vehicle_type'] ?? ''), $connection);

    $extraHours = max(0, $hoursStayed - $includedHours);
    $baseComponent = $baseAmount * $multiplier;
    $extraComponent = $extraHours * $extraHourlyRate * $multiplier;

    $surchargePercent = 0.0;
    $configuredSurcharge = (float) system_settings_value('night_rate_surcharge_percent', $connection);

    if ($configuredSurcharge > 0 && booth_time_is_in_night_band(
        $actualTimeIn,
        (string) system_settings_value('night_rate_start', $connection),
        (string) system_settings_value('night_rate_end', $connection)
    )) {
        $surchargePercent = $configuredSurcharge;
    }

    $surcharge = ($baseComponent + $extraComponent) * ($surchargePercent / 100);
    $gross = $baseComponent + $extraComponent + $surcharge;

    $discountType = booth_normalize_discount_type($context['discount_type'] ?? null);
    $discountPercent = $discountType === 'None'
        ? 0.0
        : (float) system_settings_value('statutory_discount_percent', $connection);
    $discountAmount = round($gross * ($discountPercent / 100), 2);
    $finalTotal = round(max(0.0, $gross - $discountAmount), 2);

    return [
        'total_hours_stayed' => (float) $hoursStayed,
        'base_amount' => round($baseComponent, 2),
        // extra_fee keeps meaning "everything past the included hours", which is
        // what the booth screen and the admin tables already label it.
        'extra_fee' => round($extraComponent + $surcharge, 2),
        'night_surcharge' => round($surcharge, 2),
        'vehicle_multiplier' => round($multiplier, 2),
        'gross_amount' => round($gross, 2),
        'discount_type' => $discountType,
        'discount_percent' => round($discountPercent, 2),
        'discount_amount' => $discountAmount,
        'total_payment' => $finalTotal,
        'reservation_fee' => round(max(0.00, $reservationFee), 2)
    ];
}

/** Only the two statutory discounts are recognised; anything else is 'None'. */
function booth_normalize_discount_type($value): string
{
    $normalized = strtolower(trim((string) ($value ?? '')));

    if (in_array($normalized, ['senior', 'senior citizen', 'senior_citizen', 'sc'], true)) {
        return 'Senior';
    }

    if (in_array($normalized, ['pwd', 'disability', 'person with disability'], true)) {
        return 'PWD';
    }

    return 'None';
}

function booth_build_transaction_query(): string
{
    return "
        SELECT
            r.id AS reservation_id,
            r.user_id,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name) AS full_name,
            COALESCE(NULLIF(TRIM(r.email), ''), u.email) AS email,
            r.barcode_value,
            COALESCE(r.barcode_status, 'active') AS barcode_status,
            r.parking_floor,
            r.parking_slot,
            r.reservation_date,
            r.reserved_time_in,
            r.reserved_time_out,
            r.reservation_fee,
            r.status AS reservation_status,
            pt.id AS transaction_id,
            pt.actual_time_in,
            pt.actual_time_out,
            COALESCE(pt.total_hours_stayed, pt.total_hours, 0) AS total_hours_stayed,
            COALESCE(pt.extra_fee, pt.overtime_fee, 0) AS extra_fee,
            COALESCE(pt.total_payment, r.reservation_fee, 0) AS total_payment,
            COALESCE(pt.gross_amount, 0) AS gross_amount,
            COALESCE(pt.discount_type, 'None') AS discount_type,
            COALESCE(pt.discount_percent, 0) AS discount_percent,
            COALESCE(pt.discount_amount, 0) AS discount_amount,
            COALESCE(pt.vehicle_type, r.walk_in_vehicle_type, v.vehicle_type) AS vehicle_type,
            COALESCE(v.plate_number, r.walk_in_plate) AS plate_number,
            pt.payment_method,
            pt.payment_reference,
            pt.amount_tendered,
            pt.change_due,
            COALESCE(r.is_walk_in, 0) AS is_walk_in,
            COALESCE(pt.payment_status, 'Reserved') AS payment_status,
            COALESCE(pt.booth_status, 'Reserved') AS booth_status,
            pt.paid_at,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS last_updated_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN vehicles v ON v.vehicle_id = r.vehicle_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
    ";
}

function booth_find_transaction_by_barcode(mysqli $connection, string $barcodeLookup, bool $forUpdate = false): ?array
{
    $sql = booth_build_transaction_query() . "
        WHERE (
            COALESCE(NULLIF(r.barcode_lookup, ''), REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(r.barcode_value)), ' ', ''), '-', ''), CHAR(13), ''), CHAR(10), ''), CHAR(9), ''), CHAR(160), '')) = ?
            OR COALESCE(NULLIF(r.short_code_lookup, ''), '') = ?
        )
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->bind_param('ss', $barcodeLookup, $barcodeLookup);
    $statement->execute();
    $result = $statement->get_result();

    return $result->fetch_assoc() ?: null;
}

function booth_find_transaction_by_reservation_id(mysqli $connection, int $reservationId, bool $forUpdate = false): ?array
{
    $sql = booth_build_transaction_query() . "
        WHERE r.id = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->bind_param('i', $reservationId);
    $statement->execute();
    $result = $statement->get_result();

    return $result->fetch_assoc() ?: null;
}

function booth_status_label(array $record): string
{
    if (
        strtoupper(trim((string) ($record['reservation_status'] ?? 'Reserved'))) === 'CANCELLED'
        || strtolower(trim((string) ($record['barcode_status'] ?? 'active'))) === 'cancelled'
    ) {
        return 'Cancelled';
    }

    if (strtolower(trim((string) ($record['barcode_status'] ?? 'active'))) === 'expired') {
        return 'Expired';
    }

    if (($record['payment_status'] ?? '') === 'Paid' || ($record['booth_status'] ?? '') === 'Completed') {
        return 'Paid';
    }

    if (!empty($record['actual_time_out'])) {
        return 'Time Out Recorded';
    }

    if (!empty($record['actual_time_in'])) {
        return 'Time In Recorded';
    }

    return 'Ready to Scan';
}

function booth_format_transaction(array $record): array
{
    $barcode = (string) ($record['barcode_value'] ?? '');
    $barcodeStatus = (string) ($record['barcode_status'] ?? 'active');
    $paymentStatus = (string) ($record['payment_status'] ?? 'Reserved');
    $boothStatus = (string) ($record['booth_status'] ?? 'Reserved');
    $reservationStatus = (string) ($record['reservation_status'] ?? 'Reserved');
    $totalHoursStayed = round((float) ($record['total_hours_stayed'] ?? 0), 2);
    $extraFee = round((float) ($record['extra_fee'] ?? 0), 2);
    $totalPayment = round((float) ($record['total_payment'] ?? 0), 2);
    $reservationFee = round((float) ($record['reservation_fee'] ?? 0), 2);

    return [
        'reservation_id' => (int) $record['reservation_id'],
        'reservationId' => (int) $record['reservation_id'],
        'user_id' => isset($record['user_id']) ? (int) $record['user_id'] : null,
        'userId' => isset($record['user_id']) ? (int) $record['user_id'] : null,
        'full_name' => $record['full_name'] ?? null,
        'fullName' => $record['full_name'] ?? null,
        'email' => $record['email'] ?? null,
        'barcode_value' => $barcode,
        'barcode' => $barcode,
        'barcodeValue' => $barcode,
        'short_code' => $record['short_code'] ?? null,
        'shortCode' => $record['short_code'] ?? null,
        'barcode_status' => $barcodeStatus,
        'barcodeStatus' => $barcodeStatus,
        'parking_floor' => $record['parking_floor'] ?? null,
        'floor' => $record['parking_floor'] ?? null,
        'parking_slot' => $record['parking_slot'] ?? null,
        'slot' => $record['parking_slot'] ?? null,
        'reservation_date' => $record['reservation_date'] ?? null,
        'reservationDate' => $record['reservation_date'] ?? null,
        'reserved_time_in' => $record['reserved_time_in'] ?? null,
        'reservedTimeIn' => $record['reserved_time_in'] ?? null,
        'reserved_time_out' => $record['reserved_time_out'] ?? null,
        'reservedTimeOut' => $record['reserved_time_out'] ?? null,
        'reservation_fee' => $reservationFee,
        'reservationFee' => $reservationFee,
        'status' => $reservationStatus,
        'reservation_status' => $reservationStatus,
        'reservationStatus' => $reservationStatus,
        'actual_time_in' => $record['actual_time_in'] ?: null,
        'actualTimeIn' => $record['actual_time_in'] ?: null,
        'actual_time_out' => $record['actual_time_out'] ?: null,
        'actualTimeOut' => $record['actual_time_out'] ?: null,
        'total_hours_stayed' => $totalHoursStayed,
        'totalHoursStayed' => $totalHoursStayed,
        'total_hours' => $totalHoursStayed,
        'totalHours' => $totalHoursStayed,
        'extra_fee' => $extraFee,
        'extraFee' => $extraFee,
        'overtime_fee' => $extraFee,
        'overtimeFee' => $extraFee,
        'total_payment' => $totalPayment,
        'totalPayment' => $totalPayment,
        'payment_status' => $paymentStatus,
        'paymentStatus' => $paymentStatus,
        'booth_status' => $boothStatus,
        'boothStatus' => $boothStatus,
        'paid_at' => $record['paid_at'] ?: null,
        'paidAt' => $record['paid_at'] ?: null,
        'last_updated_at' => $record['last_updated_at'] ?: null,
        'lastUpdatedAt' => $record['last_updated_at'] ?: null,
        'vehicle_type' => $record['vehicle_type'] ?? null,
        'vehicleType' => $record['vehicle_type'] ?? null,
        'plate_number' => $record['plate_number'] ?? null,
        'plateNumber' => $record['plate_number'] ?? null,
        'gross_amount' => round((float) ($record['gross_amount'] ?? 0), 2),
        'grossAmount' => round((float) ($record['gross_amount'] ?? 0), 2),
        'discount_type' => $record['discount_type'] ?? 'None',
        'discountType' => $record['discount_type'] ?? 'None',
        'discount_percent' => round((float) ($record['discount_percent'] ?? 0), 2),
        'discountPercent' => round((float) ($record['discount_percent'] ?? 0), 2),
        'discount_amount' => round((float) ($record['discount_amount'] ?? 0), 2),
        'discountAmount' => round((float) ($record['discount_amount'] ?? 0), 2),
        'payment_method' => $record['payment_method'] ?? null,
        'paymentMethod' => $record['payment_method'] ?? null,
        'payment_reference' => $record['payment_reference'] ?? null,
        'paymentReference' => $record['payment_reference'] ?? null,
        'amount_tendered' => isset($record['amount_tendered']) ? round((float) $record['amount_tendered'], 2) : null,
        'amountTendered' => isset($record['amount_tendered']) ? round((float) $record['amount_tendered'], 2) : null,
        'change_due' => isset($record['change_due']) ? round((float) $record['change_due'], 2) : null,
        'changeDue' => isset($record['change_due']) ? round((float) $record['change_due'], 2) : null,
        'is_walk_in' => (int) ($record['is_walk_in'] ?? 0) === 1,
        'isWalkIn' => (int) ($record['is_walk_in'] ?? 0) === 1,
        'statusLabel' => booth_status_label($record)
    ];
}

function booth_upsert_transaction(
    mysqli $connection,
    int $reservationId,
    ?string $actualTimeIn,
    ?string $actualTimeOut,
    float $totalHoursStayed,
    float $extraFee,
    float $totalPayment,
    string $paymentStatus,
    string $boothStatus,
    ?string $paidAt
): void {
    $statement = $connection->prepare("
        INSERT INTO parking_transactions (
            reservation_id,
            actual_time_in,
            actual_time_out,
            total_hours_stayed,
            total_hours,
            extra_fee,
            overtime_fee,
            total_payment,
            payment_status,
            booth_status,
            paid_at,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            actual_time_in = VALUES(actual_time_in),
            actual_time_out = VALUES(actual_time_out),
            total_hours_stayed = VALUES(total_hours_stayed),
            total_hours = VALUES(total_hours),
            extra_fee = VALUES(extra_fee),
            overtime_fee = VALUES(overtime_fee),
            total_payment = VALUES(total_payment),
            payment_status = VALUES(payment_status),
            booth_status = VALUES(booth_status),
            paid_at = VALUES(paid_at),
            updated_at = NOW()
    ");

    $statement->bind_param(
        'issdddddsss',
        $reservationId,
        $actualTimeIn,
        $actualTimeOut,
        $totalHoursStayed,
        $totalHoursStayed,
        $extraFee,
        $extraFee,
        $totalPayment,
        $paymentStatus,
        $boothStatus,
        $paidAt
    );
    $statement->execute();
}

/**
 * Store the pricing breakdown beside the total.
 *
 * booth_upsert_transaction() writes the money columns the booth has always
 * had; this records *how* the total was reached, which is what makes a
 * discounted or surcharged stay auditable after the fact.
 */
function booth_apply_transaction_pricing(mysqli $connection, int $reservationId, array $payment, ?string $vehicleType = null): void
{
    $statement = $connection->prepare("
        UPDATE parking_transactions
        SET vehicle_type = ?,
            discount_type = ?,
            discount_percent = ?,
            discount_amount = ?,
            gross_amount = ?,
            updated_at = NOW()
        WHERE reservation_id = ?
    ");

    $discountType = (string) ($payment['discount_type'] ?? 'None');
    $discountPercent = round((float) ($payment['discount_percent'] ?? 0), 2);
    $discountAmount = round((float) ($payment['discount_amount'] ?? 0), 2);
    $grossAmount = round((float) ($payment['gross_amount'] ?? 0), 2);

    $statement->bind_param(
        'ssdddi',
        $vehicleType,
        $discountType,
        $discountPercent,
        $discountAmount,
        $grossAmount,
        $reservationId
    );
    $statement->execute();
}

/** Record how the money actually arrived: tender, reference, change, teller. */
function booth_apply_transaction_tender(mysqli $connection, int $reservationId, array $tender, ?int $staffId = null): void
{
    $statement = $connection->prepare("
        UPDATE parking_transactions
        SET payment_method = ?,
            payment_reference = ?,
            amount_tendered = ?,
            change_due = ?,
            settled_by_staff_id = ?,
            updated_at = NOW()
        WHERE reservation_id = ?
    ");

    $method = (string) ($tender['payment_method'] ?? 'Cash');
    $reference = $tender['payment_reference'] !== '' ? (string) ($tender['payment_reference'] ?? '') : null;
    $amountTendered = isset($tender['amount_tendered']) ? round((float) $tender['amount_tendered'], 2) : null;
    $changeDue = isset($tender['change_due']) ? round((float) $tender['change_due'], 2) : null;

    $statement->bind_param(
        'ssddii',
        $method,
        $reference,
        $amountTendered,
        $changeDue,
        $staffId,
        $reservationId
    );
    $statement->execute();
}

/** Tender types the booth accepts. Cash is the default for a walk-up. */
function booth_normalize_payment_method($value): string
{
    $normalized = strtolower(trim((string) ($value ?? '')));
    $allowed = [
        'cash' => 'Cash',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'paymaya' => 'Maya',
        'qrph' => 'QR Ph',
        'qr ph' => 'QR Ph',
        'card' => 'Card',
        'bank' => 'Bank Transfer',
        'bank transfer' => 'Bank Transfer'
    ];

    return $allowed[$normalized] ?? 'Cash';
}

/**
 * Admit a driver who arrived without booking.
 *
 * The booth could previously only work from a reservation that already
 * existed, so a walk-up could not be let in at all. This mints the same
 * records the online flow produces -- reservation, barcode, transaction --
 * except it starts already timed in, because the car is at the gate now.
 */
function booth_issue_walkin_reservation(mysqli $connection, array $payload, array $boothUser): array
{
    $plate = strtoupper(trim((string) ($payload['plateNumber'] ?? $payload['plate_number'] ?? '')));
    $vehicleType = trim((string) ($payload['vehicleType'] ?? $payload['vehicle_type'] ?? 'Car'));
    $driverName = trim((string) ($payload['fullName'] ?? $payload['full_name'] ?? ''));
    $requestedFloor = trim((string) ($payload['parkingFloor'] ?? $payload['parking_floor'] ?? ''));
    $requestedSlot = strtoupper(trim((string) ($payload['parkingSlot'] ?? $payload['parking_slot'] ?? '')));

    if ($plate === '') {
        booth_error('Enter the plate number before issuing a walk-in ticket.', 422);
    }

    if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,14}$/', $plate)) {
        booth_error('That plate number does not look valid.', 422);
    }

    $slot = booth_find_available_slot($connection, $requestedFloor, $requestedSlot);

    if (!$slot) {
        booth_error(
            $requestedSlot !== ''
                ? 'That slot is not available right now.'
                : 'No parking slot is available right now.',
            409
        );
    }

    $floorName = (string) $slot['floor_name'];
    $slotCode = (string) $slot['slot_code'];
    $now = booth_get_database_now($connection);
    $barcodeValue = booth_generate_walkin_barcode($connection, $floorName, $slotCode);
    $barcodeLookup = booth_lookup_barcode($barcodeValue);
    $reservationFee = system_settings_base_rate($connection);
    $fullName = $driverName !== '' ? $driverName : 'Walk-in ' . $plate;
    $staffId = isset($boothUser['id']) ? (int) $boothUser['id'] : null;

    $statement = $connection->prepare("
        INSERT INTO reservations (
            user_id, vehicle_id, barcode_value, barcode_lookup, barcode_status,
            full_name, email, parking_floor, parking_slot, parking_slot_id,
            reservation_date, reserved_time_in, reservation_fee, status,
            is_walk_in, walk_in_plate, walk_in_vehicle_type, issued_by_staff_id,
            created_at, updated_at
        )
        VALUES (
            NULL, NULL, ?, ?, 'scanned',
            ?, NULL, ?, ?, ?,
            DATE(?), TIME(?), ?, 'Parked',
            1, ?, ?, ?,
            NOW(), NOW()
        )
    ");

    $slotId = isset($slot['id']) ? (int) $slot['id'] : null;
    $statement->bind_param(
        'sssssissdssi',
        $barcodeValue,
        $barcodeLookup,
        $fullName,
        $floorName,
        $slotCode,
        $slotId,
        $now,
        $now,
        $reservationFee,
        $plate,
        $vehicleType,
        $staffId
    );
    $statement->execute();

    $reservationId = (int) $connection->insert_id;

    if ($reservationId <= 0) {
        booth_error('Failed to issue the walk-in ticket.', 500);
    }

    booth_upsert_transaction(
        $connection,
        $reservationId,
        $now,
        null,
        0.0,
        0.0,
        0.0,
        'Reserved',
        'Parked',
        null
    );

    $pricingStatement = $connection->prepare("
        UPDATE parking_transactions SET vehicle_type = ?, updated_at = NOW() WHERE reservation_id = ?
    ");
    $pricingStatement->bind_param('si', $vehicleType, $reservationId);
    $pricingStatement->execute();

    return [
        'reservation_id' => $reservationId,
        'barcode' => $barcodeValue,
        'floor' => $floorName,
        'slot' => $slotCode
    ];
}

/** A walk-in barcode is the same shape as an online one, marked WI. */
function booth_generate_walkin_barcode(mysqli $connection, string $floorName, string $slotCode): string
{
    $compactFloor = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $floorName));
    $compactSlot = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $slotCode));

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $seed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $candidate = booth_normalize_barcode("SP-WI-{$compactFloor}-{$compactSlot}-{$seed}");
        $lookup = booth_lookup_barcode($candidate);

        $statement = $connection->prepare("SELECT id FROM reservations WHERE barcode_lookup = ? LIMIT 1");
        $statement->bind_param('s', $lookup);
        $statement->execute();
        $taken = (bool) $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$taken) {
            return $candidate;
        }
    }

    booth_error('Failed to generate a unique walk-in barcode.', 500);
}

/**
 * First slot nobody is holding, locked for update.
 *
 * A slot counts as taken while a reservation on it is still active and has not
 * timed out, which is the same rule the driver-facing monitor applies.
 */
function booth_find_available_slot(mysqli $connection, string $floorName = '', string $slotCode = ''): ?array
{
    $sql = "
        SELECT s.id, s.slot_code, f.floor_name
        FROM parking_slots s
        JOIN parking_floors f ON f.id = s.floor_id
        WHERE s.is_active = 1
          AND s.status NOT IN ('Inactive', 'Unavailable')
          AND NOT EXISTS (
              SELECT 1
              FROM reservations r
              LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
              WHERE r.parking_floor = f.floor_name
                AND r.parking_slot = s.slot_code
                AND LOWER(COALESCE(r.barcode_status, 'active')) = 'active'
                AND UPPER(COALESCE(r.status, 'Reserved')) NOT IN ('COMPLETED', 'CANCELLED')
                AND (pt.actual_time_out IS NULL OR pt.actual_time_out = '0000-00-00 00:00:00')
          )
    ";

    $types = '';
    $params = [];

    if ($floorName !== '') {
        $sql .= " AND f.floor_name = ? ";
        $types .= 's';
        $params[] = $floorName;
    }

    if ($slotCode !== '') {
        $sql .= " AND UPPER(s.slot_code) = ? ";
        $types .= 's';
        $params[] = $slotCode;
    }

    $sql .= " ORDER BY f.floor_name ASC, LENGTH(s.slot_code) ASC, s.slot_code ASC LIMIT 1 FOR UPDATE";

    $statement = $connection->prepare($sql);

    if ($types !== '') {
        $statement->bind_param($types, ...$params);
    }

    $statement->execute();

    return $statement->get_result()->fetch_assoc() ?: null;
}

function booth_update_reservation_status(mysqli $connection, int $reservationId, string $status): void
{
    $statement = $connection->prepare("
        UPDATE reservations
        SET status = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $statement->bind_param('si', $status, $reservationId);
    $statement->execute();
}

function booth_update_reservation_barcode_status(mysqli $connection, int $reservationId, string $barcodeStatus): void
{
    $statement = $connection->prepare("
        UPDATE reservations
        SET barcode_status = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $statement->bind_param('si', $barcodeStatus, $reservationId);
    $statement->execute();
}
