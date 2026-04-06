<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

parking_bootstrap_endpoint('GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    booth_error('Method not allowed.', 405);
}

try {
    if ($userId <= 0) {
        booth_error('No active user session found.', 401);
    }

    $connection = booth_db();
    $reservations = parking_get_user_reservations($connection, $userId);

    booth_success('User reservations loaded successfully.', [
        'reservations' => $reservations
    ]);
} catch (Throwable $exception) {
    booth_log('user-get-reservations-failed', [
        'error' => $exception->getMessage()
    ]);
    booth_error('Failed to load user reservations.', 500, [
        'details' => $exception->getMessage()
    ]);
}
