<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();

    $summaryRow = $connection->query("
        SELECT
            COALESCE(SUM(CASE WHEN COALESCE(pt.payment_status, p.payment_status, 'Unpaid') = 'Paid' THEN COALESCE(pt.total_payment, p.amount, 0) ELSE 0 END), 0) AS total_income,
            SUM(CASE WHEN COALESCE(pt.payment_status, p.payment_status, 'Unpaid') = 'Paid' THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN COALESCE(pt.payment_status, p.payment_status, 'Unpaid') <> 'Paid' THEN 1 ELSE 0 END) AS unpaid_count
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        LEFT JOIN payments p ON p.reservation_id = r.id
    ")->fetch_assoc() ?: [];

    $records = [];
    $result = $connection->query("
        SELECT
            r.id,
            COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Reservation Holder') AS full_name,
            r.barcode_value,
            COALESCE(pt.total_payment, p.amount, 0) AS total_payment,
            COALESCE(pt.payment_status, p.payment_status, 'Unpaid') AS payment_status,
            COALESCE(pt.paid_at, p.paid_at, NULL) AS paid_at,
            COALESCE(pt.booth_status, r.status, 'Reserved') AS booth_status,
            COALESCE(pt.actual_time_in, NULL) AS actual_time_in,
            COALESCE(pt.actual_time_out, NULL) AS actual_time_out,
            COALESCE(pt.updated_at, r.updated_at, r.created_at) AS updated_at
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        LEFT JOIN payments p ON p.reservation_id = r.id
        ORDER BY COALESCE(pt.paid_at, p.paid_at, pt.updated_at, r.updated_at, r.created_at) DESC
    ");

    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }

    admin_success('Payments loaded successfully.', [
        'summary' => [
            'totalIncome' => (float) ($summaryRow['total_income'] ?? 0),
            'paidCount' => (int) ($summaryRow['paid_count'] ?? 0),
            'unpaidCount' => (int) ($summaryRow['unpaid_count'] ?? 0)
        ],
        'payments' => $records
    ]);
} catch (Throwable $exception) {
    admin_log('get-payments-failed', [
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load payments.', 500, [
        'details' => $exception->getMessage()
    ]);
}

