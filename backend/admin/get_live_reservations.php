<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    $reservations = parking_get_live_reservations($connection, $limit);

    admin_success('Live reservations loaded successfully.', [
        'reservations' => $reservations
    ]);
} catch (Throwable $exception) {
    admin_log('admin-get-live-reservations-failed', [
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load live reservations.', 500, [
        'details' => $exception->getMessage()
    ]);
}
