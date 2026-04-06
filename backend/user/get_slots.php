<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';

parking_bootstrap_endpoint('GET');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    booth_error('Method not allowed.', 405);
}

try {
    $connection = booth_db();
    $floorName = parking_clean_text($_GET['floor_name'] ?? '');
    $floorId = isset($_GET['floor_id']) ? (int) $_GET['floor_id'] : null;

    if ($floorName === '' && (!$floorId || $floorId <= 0)) {
        booth_error('A floor_name or floor_id is required.', 422);
    }

    $slots = parking_get_slots($connection, $floorName !== '' ? $floorName : null, $floorId, true);

    booth_success('Parking slots loaded successfully.', [
        'slots' => $slots
    ]);
} catch (Throwable $exception) {
    booth_log('user-get-slots-failed', [
        'error' => $exception->getMessage()
    ]);
    booth_error('Failed to load parking slots.', 500, [
        'details' => $exception->getMessage()
    ]);
}
