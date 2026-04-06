<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/monitor-helpers.php';
require_once __DIR__ . '/../parking/common.php';

booth_bootstrap_endpoint('GET', 'view_monitor');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    booth_error('Method not allowed. Use GET.', 405);
}

try {
    $connection = booth_db();
    reservation_security_expire_due_reservations($connection);
    parking_sync_slot_statuses($connection);
    $selectedFloor = (string) ($_GET['floor'] ?? 'LG');

    booth_log('slot-monitor-request', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'floor' => $selectedFloor
    ]);

    $payload = booth_build_floor_monitor_payload($connection, $selectedFloor);

    booth_json_response([
        'success' => true,
        'message' => 'Slot monitor loaded successfully.',
        'floor' => $payload['floor'],
        'summary' => $payload['summary'],
        'slots' => $payload['slots'],
        'active_reservations' => $payload['active_reservations']
    ], 200);
} catch (Throwable $exception) {
    booth_log('slot-monitor-error', [
        'error' => $exception->getMessage()
    ]);

    booth_json_response([
        'success' => false,
        'message' => 'Unable to load the slot monitor.',
        'floor' => null,
        'summary' => [
            'available' => 0,
            'reserved' => 0,
            'occupied' => 0
        ],
        'slots' => [],
        'active_reservations' => [],
        'data' => [
            'details' => $exception->getMessage()
        ]
    ], 500);
}
