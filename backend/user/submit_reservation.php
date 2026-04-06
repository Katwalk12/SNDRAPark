<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

parking_bootstrap_endpoint('POST');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    booth_error('Method not allowed.', 405);
}

try {
    $payload = parking_request_data();
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    $userId = $sessionUserId > 0 ? $sessionUserId : (int) ($payload['userId'] ?? $payload['user_id'] ?? 0);

    if ($userId <= 0) {
        booth_error('No active user session found.', 401);
    }

    $connection = booth_db();
    $reservation = parking_create_reservation($connection, [
        'user_id' => $userId,
        'barcode_value' => $payload['barcodeValue'] ?? $payload['barcode_value'] ?? $payload['barcode'] ?? '',
        'parking_floor' => $payload['parkingFloor'] ?? $payload['parking_floor'] ?? '',
        'parking_slot' => $payload['parkingSlot'] ?? $payload['parking_slot'] ?? '',
        'full_name' => $payload['fullName'] ?? $payload['full_name'] ?? '',
        'email' => $payload['email'] ?? '',
        'reservation_date' => $payload['reservationDate'] ?? $payload['reservation_date'] ?? '',
        'reserved_time_in' => $payload['reservedTimeIn'] ?? $payload['reserved_time_in'] ?? '',
        'reservation_fee' => $payload['reservationFee'] ?? $payload['reservation_fee'] ?? null
    ]);

    booth_success('Reservation created successfully.', $reservation, 201);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();

    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    booth_log('user-submit-reservation-failed', [
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    booth_error(
        $status === 409 ? 'This parking slot is no longer available.' : $exception->getMessage(),
        $status,
        $status >= 500 ? ['details' => $exception->getMessage()] : []
    );
}
