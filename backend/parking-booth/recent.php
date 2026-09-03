<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/parking-log-feed.php';

booth_bootstrap_endpoint('GET', 'view_monitor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    booth_error('Method not allowed. Use GET.', 405);
}

try {
    booth_log_debug('recent-request', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'
    ]);

    $connection = booth_db();
    $records = parking_fetch_recent_booth_activity($connection, 20);

    booth_success('Recent activity loaded successfully.', [
        'records' => $records,
        'live_at' => booth_get_database_now($connection)
    ]);
} catch (Throwable $exception) {
    booth_log('recent-error', [
        'error' => $exception->getMessage()
    ]);

    booth_error('Unable to load recent activity.', 500, [
        'details' => $exception->getMessage()
    ]);
}
