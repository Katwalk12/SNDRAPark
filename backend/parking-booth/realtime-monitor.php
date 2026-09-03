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
    booth_log_debug('realtime-monitor-request', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'floor' => $_GET['floor'] ?? 'LG'
    ]);

    $connection = booth_db();
    reservation_security_expire_due_reservations($connection);
    parking_sync_slot_statuses($connection);
    $limit = 50;
    $floorPayload = booth_build_floor_monitor_payload($connection, (string) ($_GET['floor'] ?? 'LG'));

    $recordsSql = booth_build_transaction_query() . "
        WHERE UPPER(COALESCE(r.status, 'Reserved')) <> 'CANCELLED'
        ORDER BY COALESCE(pt.updated_at, r.updated_at, r.created_at) DESC
        LIMIT ?
    ";

    $recordsStatement = $connection->prepare($recordsSql);
    $recordsStatement->bind_param('i', $limit);
    $recordsStatement->execute();
    $recordsResult = $recordsStatement->get_result();

    $records = [];

    while ($row = $recordsResult->fetch_assoc()) {
        $records[] = booth_format_transaction($row);
    }

    $summaryResult = $connection->query("
        SELECT
            SUM(
                CASE
                    WHEN r.reservation_date = CURDATE()
                     AND COALESCE(pt.actual_time_in, '') = ''
                     AND UPPER(COALESCE(r.status, 'Reserved')) = 'RESERVED'
                    THEN 1
                    ELSE 0
                END
            ) AS reserved_today,
            SUM(
                CASE
                    WHEN COALESCE(pt.actual_time_in, '') <> ''
                     AND COALESCE(pt.actual_time_out, '') = ''
                    THEN 1
                    ELSE 0
                END
            ) AS active_parked,
            SUM(
                CASE
                    WHEN UPPER(COALESCE(pt.payment_status, '')) = 'UNPAID'
                      OR UPPER(COALESCE(pt.booth_status, '')) IN ('EXITED', 'UNPAID')
                      OR UPPER(COALESCE(r.status, '')) = 'UNPAID'
                    THEN 1
                    ELSE 0
                END
            ) AS unpaid,
            SUM(
                CASE
                    WHEN UPPER(COALESCE(pt.payment_status, '')) = 'PAID'
                     AND DATE(COALESCE(pt.paid_at, pt.updated_at, NOW())) = CURDATE()
                    THEN 1
                    ELSE 0
                END
            ) AS paid_today
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE UPPER(COALESCE(r.status, 'Reserved')) <> 'CANCELLED'
    ");

    $summaryRow = $summaryResult ? ($summaryResult->fetch_assoc() ?: []) : [];

    booth_json_response([
        'success' => true,
        'message' => 'Realtime monitor loaded successfully.',
        'floor' => $floorPayload['floor'],
        'summary' => $floorPayload['summary'],
        'slots' => $floorPayload['slots'],
        'active_reservations' => $floorPayload['active_reservations'],
        'data' => [
            'records' => $records,
            'summary' => [
                'reserved_today' => (int) ($summaryRow['reserved_today'] ?? 0),
                'active_parked' => (int) ($summaryRow['active_parked'] ?? 0),
                'unpaid' => (int) ($summaryRow['unpaid'] ?? 0),
                'paid_today' => (int) ($summaryRow['paid_today'] ?? 0)
            ],
            'slot_summary' => $floorPayload['summary'],
            'slots' => $floorPayload['slots'],
            'active_reservations' => $floorPayload['active_reservations'],
            'live_at' => booth_get_database_now($connection)
        ]
    ], 200);
} catch (Throwable $exception) {
    booth_log('realtime-monitor-error', [
        'error' => $exception->getMessage()
    ]);

    booth_json_response([
        'success' => false,
        'message' => 'Unable to load the realtime monitor.',
        'floor' => null,
        'summary' => [
            'available' => 0,
            'reserved' => 0,
            'occupied' => 0
        ],
        'slots' => [],
        'active_reservations' => [],
        'data' => [
            'records' => [],
            'summary' => [
                'reserved_today' => 0,
                'active_parked' => 0,
                'unpaid' => 0,
                'paid_today' => 0
            ],
            'details' => $exception->getMessage()
        ]
    ], 500);
}
