<?php
// payment.php
// Accepts POST JSON to mark a reservation payment as paid.
declare(strict_types=1);
require_once __DIR__ . '/../backend/config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$reservationId = isset($input['reservation_id']) ? (int)$input['reservation_id'] : 0;
$action = isset($input['action']) ? $input['action'] : '';

if ($reservationId <= 0) {
    booth_error('Missing reservation_id', 400);
}

$conn = booth_db();

try {
    if ($action === 'pay') {
        $conn->begin_transaction();
        $paidAt = date('Y-m-d H:i:s');

        // Update payments
        $pUpd = $conn->prepare("UPDATE payments SET payment_status = 'Paid', paid_at = ?, updated_at = NOW() WHERE reservation_id = ?");
        $pUpd->bind_param('si', $paidAt, $reservationId);
        $pUpd->execute();

        // Update parking_transactions
        $tUpd = $conn->prepare("UPDATE parking_transactions SET payment_status = 'Paid', paid_at = ?, updated_at = NOW() WHERE reservation_id = ?");
        $tUpd->bind_param('si', $paidAt, $reservationId);
        $tUpd->execute();

        $conn->commit();

        booth_success('Payment recorded', ['reservation_id' => $reservationId, 'paid_at' => $paidAt]);
    }

    booth_error('Invalid action', 400);
} catch (Throwable $ex) {
    if ($conn->in_transaction) $conn->rollback();
    booth_log('payment-error', ['reservation_id' => $reservationId, 'error' => $ex->getMessage()]);
    booth_error('Payment processing error', 500);
}
