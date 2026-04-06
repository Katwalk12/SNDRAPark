<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

parking_bootstrap_endpoint('POST');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    booth_error('Method not allowed.', 405);
}

$reservationId = 0;

try {
    if ($sessionUserId <= 0) {
        booth_error('No active user session found.', 401);
    }

    $payload = parking_request_data();
    $reservationId = (int) ($payload['reservationId'] ?? $payload['reservation_id'] ?? 0);

    $connection = booth_db();
    $reservation = parking_cancel_reservation($connection, $sessionUserId, $reservationId);

    booth_success('Reservation cancelled successfully.', [
        'reservation' => $reservation
    ]);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();

    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    booth_log('user-cancel-reservation-failed', [
        'user_id' => $sessionUserId,
        'reservation_id' => $reservationId ?? 0,
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    booth_error(
        $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to cancel reservation.',
        $status,
        $status >= 500 ? ['details' => $exception->getMessage()] : []
    );
}
