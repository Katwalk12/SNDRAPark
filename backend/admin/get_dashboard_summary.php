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
        'recentActivity' => $recentActivity
    ]);
} catch (Throwable $exception) {
    admin_log('get-dashboard-summary-failed', ['error' => $exception->getMessage()]);
    admin_error('Failed to load dashboard summary.', 500, [
        'details' => $exception->getMessage()
    ]);
}
