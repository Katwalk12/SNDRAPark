<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();
    reservation_security_expire_due_reservations($connection);
    parking_sync_slot_statuses($connection);

    $summarySql = "
        SELECT
            (SELECT COUNT(*) FROM users WHERE COALESCE(status, 'Active') <> 'Disabled') AS total_users,
            (SELECT COUNT(*) FROM reservations) AS total_reservations,
            (SELECT COUNT(*) FROM parking_slots WHERE is_active = 1 AND status <> 'Inactive') AS total_active_slots,
            (SELECT COUNT(*) FROM parking_slots WHERE is_active = 1 AND status = 'Reserved') AS total_reserved_slots,
            (SELECT COUNT(*) FROM parking_slots WHERE is_active = 1 AND status = 'Occupied') AS total_occupied_slots,
            (SELECT COALESCE(SUM(pt.total_payment), 0)
             FROM parking_transactions pt
             WHERE pt.paid_at IS NOT NULL
               AND DATE(pt.paid_at) = CURDATE()
            ) AS total_paid_today,
            (SELECT COUNT(*)
             FROM parking_transactions pt
             WHERE pt.actual_time_out IS NOT NULL
               AND (pt.payment_status = 'Unpaid' OR pt.paid_at IS NULL)
            ) AS total_unpaid
    ";

    $summaryRow = $connection->query($summarySql)->fetch_assoc() ?: [];
    $activeSlots = (int) ($summaryRow['total_active_slots'] ?? 0);
    $reservedSlots = (int) ($summaryRow['total_reserved_slots'] ?? 0);
    $occupiedSlots = (int) ($summaryRow['total_occupied_slots'] ?? 0);
    $availableSlots = max(0, $activeSlots - $reservedSlots - $occupiedSlots);

    $recentSql = "
        SELECT
            r.id,
            r.barcode_value,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(NULLIF(TRIM(r.email), ''), u.email, '--') AS email,
            COALESCE(r.parking_floor, '--') AS parking_floor,
            COALESCE(r.parking_slot, '--') AS parking_slot,
            r.reservation_date,
            r.reserved_time_in,
            pt.actual_time_in,
            pt.actual_time_out,
            COALESCE(pt.total_payment, 0) AS total_payment,
            COALESCE(pt.payment_status, 'Reserved') AS payment_status,
            CASE
                WHEN UPPER(COALESCE(r.status, 'Reserved')) = 'CANCELLED' THEN 'Cancelled'
                WHEN LOWER(COALESCE(r.barcode_status, 'active')) = 'expired' THEN 'Expired'
                ELSE COALESCE(pt.booth_status, r.status, 'Reserved')
            END AS booth_status,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS updated_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        ORDER BY COALESCE(pt.updated_at, r.updated_at, r.created_at) DESC
        LIMIT 12
    ";

    // Outcome mix for the donut. Four buckets so the ring stays readable;
    // Parked/Exited fold into "In progress" because both are still in flight.
    $statusMixSql = "
        SELECT
            CASE
                WHEN UPPER(COALESCE(r.status, 'Reserved')) = 'CANCELLED' THEN 'Cancelled'
                WHEN LOWER(COALESCE(r.barcode_status, 'active')) = 'expired' THEN 'Expired'
                WHEN UPPER(COALESCE(pt.booth_status, r.status, 'Reserved')) IN ('COMPLETED', 'PAID') THEN 'Completed'
                ELSE 'In progress'
            END AS bucket,
            COUNT(*) AS total
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        GROUP BY bucket
    ";

    $statusCounts = ['Completed' => 0, 'Cancelled' => 0, 'In progress' => 0, 'Expired' => 0];
    $statusResult = $connection->query($statusMixSql);

    while ($row = $statusResult->fetch_assoc()) {
        $bucket = (string) ($row['bucket'] ?? '');

        if (array_key_exists($bucket, $statusCounts)) {
            $statusCounts[$bucket] = (int) ($row['total'] ?? 0);
        }
    }

    $statusMix = [];

    foreach ($statusCounts as $label => $total) {
        $statusMix[] = ['label' => $label, 'total' => $total];
    }

    // Revenue by month. The query only returns months that had a payment, so
    // the twelve-month window is built here and the gaps stay as real zeroes
    // rather than collapsing the time axis.
    $salesSql = "
        SELECT
            DATE_FORMAT(pt.paid_at, '%Y-%m') AS month_key,
            COALESCE(SUM(pt.total_payment), 0) AS revenue,
            COUNT(*) AS transactions
        FROM parking_transactions pt
        WHERE pt.paid_at IS NOT NULL
          AND pt.paid_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
        GROUP BY month_key
    ";

    $salesByMonth = [];
    $salesResult = $connection->query($salesSql);

    while ($row = $salesResult->fetch_assoc()) {
        $salesByMonth[(string) $row['month_key']] = [
            'revenue' => (float) $row['revenue'],
            'transactions' => (int) $row['transactions']
        ];
    }

    $anchorRow = $connection->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-01') AS anchor")->fetch_assoc() ?: [];
    $anchor = new DateTimeImmutable((string) ($anchorRow['anchor'] ?? date('Y-m-01')));
    $monthlySales = [];

    for ($offset = 11; $offset >= 0; $offset--) {
        $month = $anchor->sub(new DateInterval('P' . $offset . 'M'));
        $key = $month->format('Y-m');
        $monthlySales[] = [
            'monthKey' => $key,
            'label' => $month->format('M'),
            'fullLabel' => $month->format('F Y'),
            'revenue' => round((float) ($salesByMonth[$key]['revenue'] ?? 0), 2),
            'transactions' => (int) ($salesByMonth[$key]['transactions'] ?? 0)
        ];
    }

    // Demand by hour of day. Every hour is emitted, including the quiet ones,
    // so the axis reads as a real day rather than a list of busy hours.
    $hourSql = "
        SELECT HOUR(reserved_time_in) AS slot_hour, COUNT(*) AS total
        FROM reservations
        WHERE reserved_time_in IS NOT NULL
        GROUP BY slot_hour
    ";

    $hourCounts = [];
    $hourResult = $connection->query($hourSql);

    while ($row = $hourResult->fetch_assoc()) {
        $hourCounts[(int) $row['slot_hour']] = (int) $row['total'];
    }

    $peakHours = [];

    for ($hour = 0; $hour < 24; $hour++) {
        $peakHours[] = [
            'hour' => $hour,
            'label' => date('ga', mktime($hour, 0, 0)),
            'total' => $hourCounts[$hour] ?? 0,
            'withinHours' => $hour >= (int) substr(PARKING_OPENING_TIME, 0, 2)
                && $hour <= (int) substr(PARKING_CLOSING_TIME, 0, 2)
        ];
    }

    $floorSql = "
        SELECT COALESCE(NULLIF(TRIM(parking_floor), ''), 'Unassigned') AS floor_name,
               COUNT(*) AS total
        FROM reservations
        GROUP BY floor_name
        ORDER BY total DESC
    ";

    $floorDemand = [];
    $floorResult = $connection->query($floorSql);

    while ($row = $floorResult->fetch_assoc()) {
        $floorDemand[] = [
            'label' => (string) $row['floor_name'],
            'total' => (int) $row['total']
        ];
    }

    // Headline analytics each chart carries in its own header.
    $analyticsRow = $connection->query("
        SELECT
            (SELECT COUNT(*) FROM reservations) AS total_reservations,
            (SELECT COUNT(*) FROM reservations
              WHERE LOWER(COALESCE(barcode_status, 'active')) = 'expired') AS expired_reservations,
            (SELECT COALESCE(AVG(total_payment), 0) FROM parking_transactions
              WHERE paid_at IS NOT NULL) AS average_ticket,
            (SELECT COUNT(*) FROM parking_transactions WHERE paid_at IS NOT NULL) AS paid_transactions
    ")->fetch_assoc() ?: [];

    $totalForRate = max(1, (int) ($analyticsRow['total_reservations'] ?? 0));

    $analytics = [
        'noShowRate' => round(((int) ($analyticsRow['expired_reservations'] ?? 0) / $totalForRate) * 100, 1),
        'expiredReservations' => (int) ($analyticsRow['expired_reservations'] ?? 0),
        'averageTicket' => round((float) ($analyticsRow['average_ticket'] ?? 0), 2),
        'paidTransactions' => (int) ($analyticsRow['paid_transactions'] ?? 0)
    ];

    $recentActivity = [];
    $recentResult = $connection->query($recentSql);

    while ($row = $recentResult->fetch_assoc()) {
        $recentActivity[] = $row;
    }

    admin_success('Admin dashboard summary loaded successfully.', [
        'summary' => [
            'totalUsers' => (int) ($summaryRow['total_users'] ?? 0),
            'totalReservations' => (int) ($summaryRow['total_reservations'] ?? 0),
            'totalAvailableSlots' => $availableSlots,
            'totalOccupiedSlots' => $occupiedSlots,
            'totalReservedSlots' => $reservedSlots,
            'totalPaidToday' => (float) ($summaryRow['total_paid_today'] ?? 0),
            'totalUnpaid' => (int) ($summaryRow['total_unpaid'] ?? 0)
        ],
        'statusMix' => $statusMix,
        'peakHours' => $peakHours,
        'floorDemand' => $floorDemand,
        'analytics' => $analytics,
        'monthlySales' => $monthlySales,
        'recentActivity' => $recentActivity
    ]);
} catch (Throwable $exception) {
    admin_log('get-dashboard-summary-failed', ['error' => $exception->getMessage()]);
    admin_error('Failed to load dashboard summary.', 500, [
        'details' => $exception->getMessage()
    ]);
}
