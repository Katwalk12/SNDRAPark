<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';

parking_bootstrap_endpoint('GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    booth_error('Method not allowed.', 405);
}

try {
    $connection = booth_db();
    // Closed floors are returned too, flagged inactive, so the dashboard can
    // show them greyed out and explain why rather than silently hiding them.
    $floors = parking_get_floors($connection, false);

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
