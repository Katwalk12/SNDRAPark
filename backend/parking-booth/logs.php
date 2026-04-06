<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/system-logs.php';

booth_bootstrap_endpoint('GET', 'view_monitor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    booth_error('Method not allowed. Use GET.', 405);
}

try {
    booth_log('logs-request', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'
    ]);

    $connection = booth_db();
    $logFeed = system_logs_fetch_grouped($connection, null, 250);

    booth_success('Booth log file loaded successfully.', [
        'overallTotalPayment' => $logFeed['overallTotalPayment'] ?? 0.0,
        'groupCount' => count($logFeed['groups'] ?? []),
        'groups' => $logFeed['groups'] ?? [],
        'live_at' => booth_get_database_now($connection)
    ]);
} catch (Throwable $exception) {
    booth_log('logs-error', [
        'error' => $exception->getMessage()
    ]);

    booth_error('Unable to load booth logs.', 500, [
        'details' => $exception->getMessage()
    ]);
}
