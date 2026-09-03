<?php
// scan.php
// Receives barcode via JSON POST and processes first/second scan flows.
declare(strict_types=1);
require_once __DIR__ . '/../backend/parking-booth/common.php';

$request = booth_request_data();
$barcode = trim((string) ($request['barcode'] ?? $request['barcode_value'] ?? ''));

if ($barcode === '') {
    // Support query-string fallback for simple tests
    $barcode = trim((string) ($_GET['barcode'] ?? ''));
}

if ($barcode === '') {
    booth_error('No barcode provided', 400);
}

$conn = booth_db();
$barcodeLookup = booth_lookup_barcode($barcode);

try {
    $conn->begin_transaction();
    $reservation = booth_find_transaction_by_barcode($conn, $barcodeLookup, true);

    if (!$reservation) {
        booth_error('Reservation not found', 404);
    }

    $reservationStatus = strtolower((string) ($reservation['reservation_status'] ?? $reservation['status'] ?? ''));
    $now = booth_get_database_now($conn);

    if ($reservationStatus === 'reserved') {
        // First scan: record time in and mark as active.
        $updateReservation = $conn->prepare("UPDATE reservations SET status = 'Parked', updated_at = NOW() WHERE id = ?");
        $updateReservation->bind_param('i', $reservation['reservation_id']);
        $updateReservation->execute();

        if (!empty($reservation['parking_slot']) && !empty($reservation['parking_floor'])) {
            $updateSlot = $conn->prepare("UPDATE parking_slots SET status = 'Occupied', updated_at = NOW() WHERE slot_code = ? AND floor_name = ? LIMIT 1");
            $updateSlot->bind_param('ss', $reservation['parking_slot'], $reservation['parking_floor']);
            $updateSlot->execute();
        }

        $transaction = booth_find_transaction_by_reservation_id($conn, (int) $reservation['reservation_id'], true);

        if ($transaction) {
            $updateTransaction = $conn->prepare("UPDATE parking_transactions SET actual_time_in = ?, booth_status = 'Parked', payment_status = 'Reserved', updated_at = NOW() WHERE reservation_id = ?");
            $updateTransaction->bind_param('si', $now, $reservation['reservation_id']);
            $updateTransaction->execute();
        } else {
            $insertTransaction = $conn->prepare("INSERT INTO parking_transactions (reservation_id, actual_time_in, booth_status, payment_status, created_at, updated_at) VALUES (?, ?, 'Parked', 'Reserved', NOW(), NOW())");
            $insertTransaction->bind_param('is', $reservation['reservation_id'], $now);
            $insertTransaction->execute();
        }

        $conn->commit();

        booth_success('Time IN recorded. Entrance gate opened.', [
            'reservation' => $reservation,
            'transaction' => [
                'actual_time_in' => $now,
                'status' => 'Active'
            ],
            'status' => 'Active'
        ]);
    }

    if ($reservationStatus === 'parked' || $reservationStatus === 'active') {
        // Second scan: record time out and complete the reservation.
        $transaction = booth_find_transaction_by_reservation_id($conn, (int) $reservation['reservation_id'], true);
        $actualTimeIn = $transaction['actual_time_in'] ?? $reservation['actual_time_in'] ?? $reservation['created_at'] ?? $now;

        if (!$actualTimeIn) {
            $actualTimeIn = $now;
        }

        $start = new DateTime($actualTimeIn);
        $end = new DateTime($now);
        $secondsStayed = max(0, $end->getTimestamp() - $start->getTimestamp());
        $totalHours = max(0, $secondsStayed / 3600);
        $roundedHours = round($totalHours, 2);

        $baseRate = 50.00;
        $coveredHours = 4.0;
        $overtimeRate = 10.00;
        $overtimeHours = 0;
        $overtimeAmount = 0.00;

        if ($totalHours > $coveredHours) {
            $overtimeHours = (int) ceil($totalHours - $coveredHours);
            $overtimeAmount = $overtimeHours * $overtimeRate;
        }

        $paymentData = [
            'total_hours_stayed' => $roundedHours,
            'extra_fee' => $overtimeAmount,
            'total_payment' => $baseRate + $overtimeAmount,
            'base_rate' => $baseRate,
            'overtime_hours' => $overtimeHours,
        ];

        $updateTransaction = $conn->prepare("UPDATE parking_transactions SET actual_time_out = ?, total_hours_stayed = ?, extra_fee = ?, total_payment = ?, payment_status = 'Unpaid', booth_status = 'Exited', paid_at = NULL, updated_at = NOW() WHERE reservation_id = ?");
        $updateTransaction->bind_param('sdddi', $now, $paymentData['total_hours_stayed'], $paymentData['extra_fee'], $paymentData['total_payment'], $reservation['reservation_id']);
        $updateTransaction->execute();

        $updateReservation = $conn->prepare("UPDATE reservations SET status = 'Completed', updated_at = NOW() WHERE id = ?");
        $updateReservation->bind_param('i', $reservation['reservation_id']);
        $updateReservation->execute();

        if (!empty($reservation['parking_slot']) && !empty($reservation['parking_floor'])) {
            $updateSlot = $conn->prepare("UPDATE parking_slots SET status = 'Available', updated_at = NOW() WHERE slot_code = ? AND floor_name = ? LIMIT 1");
            $updateSlot->bind_param('ss', $reservation['parking_slot'], $reservation['parking_floor']);
            $updateSlot->execute();
        }

        $paymentRecord = $conn->prepare("SELECT id FROM payments WHERE reservation_id = ? LIMIT 1");
        $paymentRecord->bind_param('i', $reservation['reservation_id']);
        $paymentRecord->execute();
        $paymentRow = $paymentRecord->get_result()->fetch_assoc();

        if ($paymentRow && !empty($paymentRow['id'])) {
            $updatePayment = $conn->prepare("UPDATE payments SET amount = ?, payment_status = 'Unpaid', paid_at = NULL, updated_at = NOW() WHERE reservation_id = ?");
            $updatePayment->bind_param('di', $paymentData['total_payment'], $reservation['reservation_id']);
            $updatePayment->execute();
        } else {
            $insertPayment = $conn->prepare("INSERT INTO payments (reservation_id, amount, payment_status, created_at, updated_at) VALUES (?, ?, 'Unpaid', NOW(), NOW())");
            $insertPayment->bind_param('id', $reservation['reservation_id'], $paymentData['total_payment']);
            $insertPayment->execute();
        }

        $conn->commit();

        booth_success('Time OUT recorded. Exit gate opened.', [
            'reservation' => $reservation,
            'transaction' => [
                'actual_time_in' => $actualTimeIn,
                'actual_time_out' => $now,
                'duration_hours' => $paymentData['total_hours_stayed'],
                'duration_display' => sprintf('%d hr %d min', (int)$paymentData['total_hours_stayed'], (int)(($paymentData['total_hours_stayed'] - floor($paymentData['total_hours_stayed'])) * 60)),
                'base_rate' => $paymentData['base_rate'],
                'overtime_amount' => $paymentData['extra_fee'],
                'total_payment' => $paymentData['total_payment'],
            ],
            'status' => 'Completed'
        ]);
    }

    booth_error('Reservation status not valid for scanning: ' . $reservationStatus, 400);
} catch (Throwable $ex) {
    if ($conn->in_transaction) {
        $conn->rollback();
    }
    booth_log('scan-error', ['barcode' => $barcode, 'error' => $ex->getMessage()]);
    booth_error('Unexpected error: ' . $ex->getMessage(), 500);
}
