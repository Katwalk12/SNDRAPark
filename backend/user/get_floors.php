<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';

parking_bootstrap_endpoint('GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    booth_error('Method not allowed.', 405);
}

try {
    $connection = booth_db();
    $floors = parking_get_floors($connection, true);

    booth_success('Parking floors loaded successfully.', [
        'floors' => $floors
    ]);
} catch (Throwable $exception) {
    booth_log('user-get-floors-failed', [
        'error' => $exception->getMessage()
    ]);
    booth_error('Failed to load parking floors.', 500, [
        'details' => $exception->getMessage()
    ]);
}
