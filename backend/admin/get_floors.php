<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = booth_db();
    $includeInactive = parking_bool($_GET['include_inactive'] ?? true) === 1;
    $floors = parking_get_floors($connection, !$includeInactive ? true : false);

    booth_success('Admin floor list loaded successfully.', [
        'floors' => $floors
    ]);
} catch (Throwable $exception) {
    booth_log('admin-get-floors-failed', [
        'error' => $exception->getMessage()
    ]);
    booth_error('Failed to load parking floors.', 500, [
        'details' => $exception->getMessage()
    ]);
}
