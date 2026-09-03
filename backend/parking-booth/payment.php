<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../common/system-logs.php';
require_once __DIR__ . '/../common/reservation-notifier.php';

// Authenticate booth request with required permission
$boothUser = booth_bootstrap_endpoint('POST', 'process_payment');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    booth_error('Method not allowed. Use POST.', 405);
}

$connection = null;

try {
    $payload = booth_request_data();
    $action = booth_resolve_payment_action($payload);

    booth_log('payment-request', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'action' => $action,
        'barcode' => $payload['barcode'] ?? null,
        'reservation_id' => $payload['reservationId'] ?? $payload['reservation_id'] ?? null,
        'booth_user_id' => $boothUser['id'],
        'booth_location' => $boothUser['booth_location']
    ]);

    $connection = booth_db();
    $connection->begin_transaction();

    if ($action === 'scan') {
        $result = booth_process_payment_scan($connection, $payload, $boothUser);
        $connection->commit();
        $barcodeForLog = (string) ($payload['barcode'] ?? 'unknown');

        // Use the supported audit logger API so booth payment logging stays editor-clean.
        AuditLogger::log('BOOTH_PAYMENT_SCAN', 'INFO', [
            'service' => 'payment',
            'status' => 'success',
            'details' => 'Payment scanned for barcode: ' . $barcodeForLog,
            'category' => 'payment'
        ], (int) $boothUser['id']);

        booth_success($result['message'], booth_format_transaction($result['transaction']));
    }

    if ($action === 'issue_walkin') {
        $issued = booth_issue_walkin_reservation($connection, $payload, $boothUser);
        $transaction = booth_find_transaction_by_reservation_id($connection, (int) $issued['reservation_id']);
        $connection->commit();

        system_logs_write($connection, [
            'user_id' => null,
            'actor_role' => 'booth',
            'actor_name' => (string) ($boothUser['full_name'] ?? $boothUser['email'] ?? 'Booth Teller'),
            'action_type' => 'WALK_IN_TICKET_ISSUED',
            'description' => 'Walk-in ticket issued for plate '
                . (string) ($payload['plateNumber'] ?? $payload['plate_number'] ?? 'unknown')
                . ' at ' . $issued['floor'] . ' ' . $issued['slot'] . '.',
            'related_barcode' => (string) $issued['barcode'],
            'related_floor' => (string) $issued['floor'],
            'related_slot' => (string) $issued['slot'],
            'status' => 'Parked'
        ]);

        AuditLogger::log('BOOTH_WALKIN_ISSUED', 'INFO', [
            'service' => 'payment',
            'status' => 'success',
            'details' => 'Walk-in ticket issued: ' . $issued['barcode'],
            'category' => 'payment'
        ], (int) $boothUser['id']);

        booth_success('Walk-in ticket issued.', booth_format_transaction($transaction));
    }

    if ($action === 'mark_paid') {
        $result = booth_process_mark_paid($connection, $payload, $boothUser);
        $connection->commit();
        $reservationIdForLog = (string) ($payload['reservationId'] ?? $payload['reservation_id'] ?? 'unknown');

        // Emailed after the commit, so a slow SMTP server cannot hold the
        // driver at the gate or roll back a settled payment.
        $settledReservationId = (int) ($payload['reservationId'] ?? $payload['reservation_id'] ?? 0);

        if ($settledReservationId > 0) {
            reservation_notifier_send_receipt($connection, $settledReservationId);
        }

        // Use the supported audit logger API so booth payment logging stays editor-clean.
        AuditLogger::log('BOOTH_PAYMENT_MARKED', 'INFO', [
            'service' => 'payment',
            'status' => 'success',
            'details' => 'Payment marked as paid for reservation: ' . $reservationIdForLog,
            'category' => 'payment'
        ], (int) $boothUser['id']);

        booth_success($result['message'], booth_format_transaction($result['transaction']));
    }

    $connection->rollback();
    booth_error('Unsupported payment action.', 400);
} catch (Throwable $exception) {
    if ($connection instanceof mysqli) {
        try {
            $connection->rollback();
        } catch (Throwable $rollbackException) {
            // Keep the original error as the main failure.
        }
    }

    booth_log('payment-error', [
        'error' => $exception->getMessage()
    ]);

    booth_error('Unable to process the payment request.', 500, [
        'details' => $exception->getMessage()
    ]);
}

function booth_resolve_payment_action(array $payload): string
{
    $action = strtolower(trim((string) ($payload['action'] ?? '')));

    if ($action !== '') {
        return $action;
    }

    if (!empty($payload['barcode'])) {
        return 'scan';
    }

    if (!empty($payload['reservationId']) || !empty($payload['reservation_id'])) {
        return 'mark_paid';
    }

    booth_error('Please provide a valid payment action.', 400);
}

function booth_process_payment_scan(mysqli $connection, array $payload, array $boothUser): array
{
    $barcode = booth_normalize_barcode((string) ($payload['barcode'] ?? ''));
    $barcodeLookup = booth_lookup_barcode($barcode);

    if ($barcodeLookup === '') {
        booth_log('payment-scan-empty-barcode');
        booth_error('Please scan or enter a barcode first.', 400);
    }

    $transaction = booth_find_transaction_by_barcode($connection, $barcodeLookup, true);

    if (!$transaction) {
        booth_log('payment-scan-barcode-not-found', ['barcode' => $barcode]);
        booth_error('Barcode not found', 404);
    }

    reservation_security_expire_reservation_if_due($connection, (int) $transaction['reservation_id']);
    $transaction = booth_find_transaction_by_reservation_id($connection, (int) $transaction['reservation_id'], true) ?: $transaction;

    if (
        strtoupper(trim((string) ($transaction['reservation_status'] ?? 'Reserved'))) === 'CANCELLED'
        || strtolower(trim((string) ($transaction['barcode_status'] ?? 'active'))) === 'cancelled'
    ) {
        booth_log('payment-scan-barcode-cancelled', [
            'reservation_id' => (int) $transaction['reservation_id'],
            'barcode' => $barcode
        ]);
        $connection->commit();
        booth_error('This reservation has already been cancelled.', 410, [
            'transaction' => booth_format_transaction($transaction)
        ]);
    }

    if (strtolower((string) ($transaction['barcode_status'] ?? 'active')) === 'expired') {
        booth_log('payment-scan-barcode-expired', [
            'reservation_id' => (int) $transaction['reservation_id'],
            'barcode' => $barcode
        ]);
        $connection->commit();
        booth_error('This reservation barcode has expired.', 410, [
            'transaction' => booth_format_transaction($transaction)
        ]);
    }

    $reservationId = (int) $transaction['reservation_id'];
    $reservationFee = round((float) ($transaction['reservation_fee'] ?? 0), 2);

    if (empty($transaction['actual_time_in'])) {
        $actualTimeIn = booth_get_database_now($connection);

        booth_upsert_transaction(
            $connection,
            $reservationId,
            $actualTimeIn,
            null,
            0.00,
            0.00,
            0.00,
            'Pending',
            'Parked',
            null
        );
        booth_update_reservation_status($connection, $reservationId, 'Parked');
        booth_update_reservation_barcode_status($connection, $reservationId, 'scanned');

        system_logs_write($connection, [
            'user_id' => isset($transaction['user_id']) ? (int) $transaction['user_id'] : null,
            'actor_role' => 'booth',
            'actor_name' => (string) ($boothUser['full_name'] ?? $boothUser['email'] ?? 'Booth Teller'),
            'action_type' => 'BARCODE_TIME_IN_SCANNED',
            'description' => 'Time In recorded for barcode ' . $barcode . ' assigned to '
                . (string) ($transaction['full_name'] ?? 'Reservation Holder') . '.',
            'related_barcode' => $barcode,
            'related_floor' => (string) ($transaction['parking_floor'] ?? ''),
            'related_slot' => (string) ($transaction['parking_slot'] ?? ''),
            'status' => 'Parked'
        ]);

        return [
            'message' => 'Time In recorded',
            'transaction' => booth_find_transaction_by_reservation_id($connection, $reservationId)
        ];
    }

    if (!empty($transaction['actual_time_in']) && empty($transaction['actual_time_out'])) {
        $actualTimeOut = booth_get_database_now($connection);
        $vehicleType = (string) ($payload['vehicleType'] ?? $payload['vehicle_type'] ?? $transaction['vehicle_type'] ?? '');
        $discountType = booth_normalize_discount_type($payload['discountType'] ?? $payload['discount_type'] ?? $transaction['discount_type'] ?? null);
        $payment = booth_calculate_payment(
            $connection,
            (string) $transaction['actual_time_in'],
            $actualTimeOut,
            $reservationFee,
            ['vehicle_type' => $vehicleType, 'discount_type' => $discountType]
        );

        booth_upsert_transaction(
            $connection,
            $reservationId,
            (string) $transaction['actual_time_in'],
            $actualTimeOut,
            (float) $payment['total_hours_stayed'],
            (float) $payment['extra_fee'],
            (float) $payment['total_payment'],
            'Unpaid',
            'Exited',
            null
        );
        booth_apply_transaction_pricing($connection, $reservationId, $payment, $vehicleType !== '' ? $vehicleType : null);
        booth_update_reservation_status($connection, $reservationId, 'Unpaid');

        system_logs_write($connection, [
            'user_id' => isset($transaction['user_id']) ? (int) $transaction['user_id'] : null,
            'actor_role' => 'booth',
            'actor_name' => (string) ($boothUser['full_name'] ?? $boothUser['email'] ?? 'Booth Teller'),
            'action_type' => 'BARCODE_TIME_OUT_SCANNED',
            'description' => 'Time Out recorded for barcode ' . $barcode . ' assigned to '
                . (string) ($transaction['full_name'] ?? 'Reservation Holder') . '.',
            'related_barcode' => $barcode,
            'related_floor' => (string) ($transaction['parking_floor'] ?? ''),
            'related_slot' => (string) ($transaction['parking_slot'] ?? ''),
            'amount' => (float) ($payment['total_payment'] ?? 0),
            'status' => 'Unpaid'
        ]);

        return [
            'message' => 'Time Out recorded',
            'transaction' => booth_find_transaction_by_reservation_id($connection, $reservationId)
        ];
    }

    booth_log('payment-scan-already-completed', [
        'reservation_id' => $reservationId,
        'barcode' => $barcode
    ]);
    booth_error('Transaction already completed', 409, [
        'transaction' => booth_format_transaction($transaction)
    ]);
}

function booth_process_mark_paid(mysqli $connection, array $payload, array $boothUser): array
{
    $reservationId = (int) ($payload['reservationId'] ?? $payload['reservation_id'] ?? 0);

    if ($reservationId <= 0) {
        booth_error('A valid reservation ID is required.', 400);
    }

    $transaction = booth_find_transaction_by_reservation_id($connection, $reservationId, true);

    if (!$transaction) {
        booth_log('payment-mark-paid-reservation-not-found', ['reservation_id' => $reservationId]);
        booth_error('Reservation not found.', 404);
    }

    if (empty($transaction['actual_time_in'])) {
        booth_log('payment-mark-paid-missing-time-in', ['reservation_id' => $reservationId]);
        booth_error('Time In must be recorded before payment.', 409, [
            'transaction' => booth_format_transaction($transaction)
        ]);
    }

    if (empty($transaction['actual_time_out'])) {
        booth_log('payment-mark-paid-missing-time-out', ['reservation_id' => $reservationId]);
        booth_error('Time Out must be recorded before payment.', 409, [
            'transaction' => booth_format_transaction($transaction)
        ]);
    }

    if (($transaction['payment_status'] ?? '') === 'Paid' || ($transaction['booth_status'] ?? '') === 'Completed') {
        booth_log('payment-mark-paid-already-completed', ['reservation_id' => $reservationId]);
        booth_error('Transaction is already marked as paid.', 409, [
            'transaction' => booth_format_transaction($transaction)
        ]);
    }

    $paidAt = booth_get_database_now($connection);
    $amountDue = round((float) ($transaction['total_payment'] ?? 0), 2);
    $paymentMethod = booth_normalize_payment_method($payload['paymentMethod'] ?? $payload['payment_method'] ?? 'Cash');
    $paymentReference = trim((string) ($payload['paymentReference'] ?? $payload['payment_reference'] ?? ''));
    $amountTendered = isset($payload['amountTendered']) || isset($payload['amount_tendered'])
        ? round((float) ($payload['amountTendered'] ?? $payload['amount_tendered']), 2)
        : null;

    // A cashless payment must carry its reference, or the shift cannot be
    // reconciled against the wallet's own report later.
    if ($paymentMethod !== 'Cash' && $paymentReference === '') {
        booth_error('Enter the reference number for a ' . $paymentMethod . ' payment.', 422, [
            'transaction' => booth_format_transaction($transaction)
        ]);
    }

    if ($amountTendered !== null && $amountTendered + 0.001 < $amountDue) {
        booth_error(
            sprintf('Amount tendered (%.2f) is less than the amount due (%.2f).', $amountTendered, $amountDue),
            422,
            ['transaction' => booth_format_transaction($transaction)]
        );
    }

    $changeDue = $amountTendered !== null ? round(max(0.0, $amountTendered - $amountDue), 2) : null;

    booth_upsert_transaction(
        $connection,
        $reservationId,
        (string) $transaction['actual_time_in'],
        (string) $transaction['actual_time_out'],
        (float) ($transaction['total_hours_stayed'] ?? 0),
        (float) ($transaction['extra_fee'] ?? 0),
        (float) ($transaction['total_payment'] ?? 0),
        'Paid',
        'Completed',
        $paidAt
    );
    booth_apply_transaction_tender(
        $connection,
        $reservationId,
        [
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference,
            'amount_tendered' => $amountTendered,
            'change_due' => $changeDue
        ],
        isset($boothUser['id']) ? (int) $boothUser['id'] : null
    );
    booth_update_reservation_status($connection, $reservationId, 'Completed');
    booth_update_reservation_barcode_status($connection, $reservationId, 'used');

    system_logs_write($connection, [
        'user_id' => isset($transaction['user_id']) ? (int) $transaction['user_id'] : null,
        'actor_role' => 'booth',
        'actor_name' => (string) ($boothUser['full_name'] ?? $boothUser['email'] ?? 'Booth Teller'),
        'action_type' => 'PAYMENT_MARKED_AS_PAID',
        'description' => 'Payment marked as paid for barcode '
            . (string) ($transaction['barcode_value'] ?? 'Unknown Barcode')
            . ' via ' . $paymentMethod . '.',
        'related_barcode' => (string) ($transaction['barcode_value'] ?? ''),
        'related_floor' => (string) ($transaction['parking_floor'] ?? ''),
        'related_slot' => (string) ($transaction['parking_slot'] ?? ''),
        'amount' => (float) ($transaction['total_payment'] ?? 0),
        'status' => 'Paid'
    ]);

    return [
        'message' => 'Payment marked as paid successfully.',
        'transaction' => booth_find_transaction_by_reservation_id($connection, $reservationId)
    ];
}
