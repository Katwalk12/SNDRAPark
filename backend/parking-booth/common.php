<?php

declare(strict_types=1);

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
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $allowedMethods . ', OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Booth-Token, X-Booth-API-Key, X-CSRF-Token');
    header('Access-Control-Allow-Credentials: true');
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

function booth_calculate_payment(mysqli $connection, string $actualTimeIn, string $actualTimeOut, float $reservationFee): array
{
    $start = new DateTime($actualTimeIn);
    $end = new DateTime($actualTimeOut);
    $secondsStayed = max(0, $end->getTimestamp() - $start->getTimestamp());
    $hoursStayed = max(1, (int) ceil($secondsStayed / 3600));
    $baseAmount = system_settings_base_rate($connection);
    $extraHourlyRate = system_settings_extra_hourly_rate($connection);
    $extraFee = $hoursStayed > 3 ? ($hoursStayed - 3) * $extraHourlyRate : 0.00;
    $computedTotal = $baseAmount + $extraFee;
    $finalTotal = round($computedTotal, 2);

    return [
        'total_hours_stayed' => (float) $hoursStayed,
        'extra_fee' => round($extraFee, 2),
        'total_payment' => $finalTotal,
        'reservation_fee' => round(max(0.00, $reservationFee), 2)
    ];
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
            COALESCE(pt.payment_status, 'Reserved') AS payment_status,
            COALESCE(pt.booth_status, 'Reserved') AS booth_status,
            pt.paid_at,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS last_updated_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
    ";
}

function booth_find_transaction_by_barcode(mysqli $connection, string $barcodeLookup, bool $forUpdate = false): ?array
{
    $sql = booth_build_transaction_query() . "
        WHERE COALESCE(NULLIF(r.barcode_lookup, ''), REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(r.barcode_value)), ' ', ''), '-', ''), CHAR(13), ''), CHAR(10), ''), CHAR(9), ''), CHAR(160), '')) = ?
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $connection->prepare($sql);
    $statement->bind_param('s', $barcodeLookup);
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
