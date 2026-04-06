<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../parking/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();
    reservation_security_expire_due_reservations($connection);
    parking_sync_slot_statuses($connection);

    $search = '%' . admin_clean_text($_GET['search'] ?? '') . '%';
    $statusFilter = admin_clean_text($_GET['status'] ?? '');
    $paymentFilter = admin_clean_text($_GET['payment_status'] ?? '');

    $sql = "
        SELECT
            r.id,
            r.barcode_value,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            COALESCE(NULLIF(TRIM(r.email), ''), u.email, '--') AS email,
            COALESCE(r.parking_floor, '--') AS parking_floor,
            COALESCE(r.parking_slot, '--') AS parking_slot,
            r.reservation_date,
            r.reserved_time_in,
            COALESCE(r.barcode_status, 'active') AS barcode_status,
            r.status AS reservation_status,
            COALESCE(pt.actual_time_in, NULL) AS actual_time_in,
            COALESCE(pt.actual_time_out, NULL) AS actual_time_out,
            COALESCE(pt.total_hours_stayed, pt.total_hours, 0) AS total_hours,
            COALESCE(pt.total_payment, 0) AS total_payment,
            COALESCE(pt.payment_status, p.payment_status, 'Reserved') AS payment_status,
            CASE
                WHEN UPPER(COALESCE(r.status, 'Reserved')) = 'CANCELLED' THEN 'Cancelled'
                WHEN LOWER(COALESCE(r.barcode_status, 'active')) = 'expired' THEN 'Expired'
                ELSE COALESCE(pt.booth_status, r.status, 'Reserved')
            END AS booth_status,
            COALESCE(pt.paid_at, p.paid_at, NULL) AS paid_at,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS updated_at,
            r.created_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        LEFT JOIN payments p ON p.reservation_id = r.id
        WHERE (
            r.barcode_value LIKE ?
            OR COALESCE(r.full_name, u.full_name, '') LIKE ?
            OR COALESCE(r.email, u.email, '') LIKE ?
            OR COALESCE(r.parking_floor, '') LIKE ?
            OR COALESCE(r.parking_slot, '') LIKE ?
        )
    ";

    $types = 'sssss';
    $params = [$search, $search, $search, $search, $search];

    if ($statusFilter !== '') {
        $sql .= " AND CASE
                WHEN UPPER(COALESCE(r.status, 'Reserved')) = 'CANCELLED' THEN 'Cancelled'
                WHEN LOWER(COALESCE(r.barcode_status, 'active')) = 'expired' THEN 'Expired'
                ELSE COALESCE(pt.booth_status, r.status, 'Reserved')
            END = ? ";
        $types .= 's';
        $params[] = $statusFilter;
    }

    if ($paymentFilter !== '') {
        $sql .= " AND COALESCE(pt.payment_status, p.payment_status, 'Reserved') = ? ";
        $types .= 's';
        $params[] = $paymentFilter;
    }

    $sql .= " ORDER BY COALESCE(pt.updated_at, r.updated_at, r.created_at) DESC ";

    $statement = $connection->prepare($sql);
    $statement->bind_param($types, ...$params);
    $statement->execute();
    $result = $statement->get_result();

    $reservations = [];

    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }

    admin_success('Reservations loaded successfully.', [
        'reservations' => $reservations
    ]);
} catch (Throwable $exception) {
    admin_log('get-reservations-failed', [
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load reservations.', 500, [
        'details' => $exception->getMessage()
    ]);
}
