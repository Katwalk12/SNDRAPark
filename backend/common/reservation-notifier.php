<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../config/system-settings.php';

/**
 * Messages a driver should receive about their own booking.
 *
 * A reservation expires after a grace period, releases its slot and costs the
 * driver a warning -- three of which lock the account. Until now none of that
 * was announced: the notifications table was written only by violations, admin
 * announcements and feedback replies, and email existed only for password
 * resets. So the system could penalise somebody it had never warned.
 *
 * Every send here is best-effort. A dead SMTP server must never take down a
 * booking, a booth scan, or the scheduled sweep.
 */

if (!function_exists('reservation_notifier_enabled')) {
    function reservation_notifier_enabled(mysqli $connection): bool
    {
        return (int) system_settings_value('notify_email_enabled', $connection) === 1;
    }
}

if (!function_exists('reservation_notifier_add_inapp')) {
    /** Write the bell-icon notification. Works for any audience row shape. */
    function reservation_notifier_add_inapp(
        mysqli $connection,
        ?int $userId,
        ?int $reservationId,
        string $title,
        string $message
    ): void {
        if ($userId === null || $userId <= 0) {
            return;
        }

        try {
            $statement = $connection->prepare("
                INSERT INTO notifications (user_id, reservation_id, title, message, audience, notification_date, is_read)
                VALUES (?, ?, ?, ?, 'Users', CURDATE(), 0)
            ");
            $statement->bind_param('iiss', $userId, $reservationId, $title, $message);
            $statement->execute();
        } catch (Throwable $exception) {
            error_log('[reservation-notifier] in-app insert failed: ' . $exception->getMessage());
        }
    }
}

if (!function_exists('reservation_notifier_fetch')) {
    function reservation_notifier_fetch(mysqli $connection, int $reservationId): ?array
    {
        $statement = $connection->prepare("
            SELECT
                r.id,
                r.user_id,
                r.barcode_value,
                r.short_code,
                r.parking_floor,
                r.parking_slot,
                r.reservation_date,
                r.reserved_time_in,
                r.reservation_fee,
                r.confirmation_sent_at,
                r.reminder_sent_at,
                COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Driver') AS full_name,
                COALESCE(NULLIF(TRIM(r.email), ''), u.email, '') AS email
            FROM reservations r
            LEFT JOIN users u ON u.id = r.user_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $statement->bind_param('i', $reservationId);
        $statement->execute();

        return $statement->get_result()->fetch_assoc() ?: null;
    }
}

if (!function_exists('reservation_notifier_when_label')) {
    function reservation_notifier_when_label(array $reservation): string
    {
        $date = (string) ($reservation['reservation_date'] ?? '');
        $time = (string) ($reservation['reserved_time_in'] ?? '');
        $timestamp = strtotime(trim($date . ' ' . $time));

        return $timestamp ? date('D, M j, Y \a\t g:i A', $timestamp) : 'your booked time';
    }
}

if (!function_exists('reservation_notifier_send_confirmation')) {
    /** Sent the moment a booking is made: what was booked, and the code to present. */
    function reservation_notifier_send_confirmation(mysqli $connection, int $reservationId): bool
    {
        try {
            $reservation = reservation_notifier_fetch($connection, $reservationId);

            if (!$reservation || !empty($reservation['confirmation_sent_at'])) {
                return false;
            }

            $graceMinutes = system_settings_grace_minutes($connection);
            $where = trim(((string) $reservation['parking_floor']) . ' ' . ((string) $reservation['parking_slot']));
            $when = reservation_notifier_when_label($reservation);

            reservation_notifier_add_inapp(
                $connection,
                isset($reservation['user_id']) ? (int) $reservation['user_id'] : null,
                $reservationId,
                'Reservation confirmed',
                sprintf(
                    'Slot %s is held for you on %s. Present barcode %s at the booth within %d minutes of that time, or the slot is released.',
                    $where,
                    $when,
                    (string) $reservation['barcode_value'],
                    $graceMinutes
                )
            );

            $sent = false;

            if (reservation_notifier_enabled($connection)) {
                $sent = sndra_mail_send(
                    (string) $reservation['email'],
                    'Your SNDRA Park reservation is confirmed',
                    sndra_mail_layout(
                        'Reservation confirmed',
                        'Hello ' . (string) $reservation['full_name'] . ', your parking slot is reserved. Show this code at the booth.',
                        [
                            'Slot' => $where !== '' ? $where : 'Assigned at the booth',
                            'Arrival time' => $when,
                            'Grace period' => $graceMinutes . ' minutes',
                            'Reservation fee' => 'PHP ' . number_format((float) $reservation['reservation_fee'], 2)
                        ],
                        (string) $reservation['barcode_value'],
                        'Arrive within the grace period. After that the slot is released and the booking counts as a no-show.'
                    )
                );
            }

            $connection->query("UPDATE reservations SET confirmation_sent_at = NOW() WHERE id = " . (int) $reservationId);

            return $sent;
        } catch (Throwable $exception) {
            error_log('[reservation-notifier] confirmation failed: ' . $exception->getMessage());
            return false;
        }
    }
}

if (!function_exists('reservation_notifier_send_due_reminders')) {
    /**
     * Nudge every booking that is close to being released.
     *
     * Called by the scheduled sweep just before it expires anything, so the
     * warning always arrives ahead of the penalty. One reminder per booking,
     * tracked by reminder_sent_at.
     */
    function reservation_notifier_send_due_reminders(mysqli $connection): int
    {
        $graceMinutes = system_settings_grace_minutes($connection);
        $leadMinutes = (int) system_settings_value('reservation_reminder_minutes', $connection);

        if ($leadMinutes <= 0) {
            return 0;
        }

        // The window opens `leadMinutes` before the slot would be released and
        // closes when it actually is, so a sweep that runs late still catches it.
        $statement = $connection->prepare("
            SELECT r.id
            FROM reservations r
            LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
            WHERE r.reminder_sent_at IS NULL
              AND LOWER(COALESCE(r.barcode_status, 'active')) = 'active'
              AND UPPER(COALESCE(r.status, 'Reserved')) = 'RESERVED'
              AND (pt.actual_time_in IS NULL OR pt.actual_time_in = '0000-00-00 00:00:00')
              AND TIMESTAMP(r.reservation_date, COALESCE(r.reserved_time_in, '00:00:00'))
                  <= DATE_ADD(NOW(), INTERVAL ? MINUTE)
              AND DATE_ADD(
                    TIMESTAMP(r.reservation_date, COALESCE(r.reserved_time_in, '00:00:00')),
                    INTERVAL ? MINUTE
                  ) > NOW()
            LIMIT 100
        ");
        $statement->bind_param('ii', $leadMinutes, $graceMinutes);
        $statement->execute();
        $result = $statement->get_result();

        $ids = [];

        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }

        $sentCount = 0;

        foreach ($ids as $reservationId) {
            $reservation = reservation_notifier_fetch($connection, $reservationId);

            if (!$reservation) {
                continue;
            }

            $where = trim(((string) $reservation['parking_floor']) . ' ' . ((string) $reservation['parking_slot']));

            reservation_notifier_add_inapp(
                $connection,
                isset($reservation['user_id']) ? (int) $reservation['user_id'] : null,
                $reservationId,
                'Your slot is about to be released',
                sprintf(
                    'Slot %s is still waiting for you. Scan barcode %s at the booth within %d minutes of your arrival time or the reservation expires and counts as a no-show.',
                    $where,
                    (string) $reservation['barcode_value'],
                    $graceMinutes
                )
            );

            if (reservation_notifier_enabled($connection)) {
                sndra_mail_send(
                    (string) $reservation['email'],
                    'Your SNDRA Park slot is about to be released',
                    sndra_mail_layout(
                        'Your slot is about to be released',
                        'Hello ' . (string) $reservation['full_name'] . ', your reservation has not been scanned yet.',
                        [
                            'Slot' => $where,
                            'Arrival time' => reservation_notifier_when_label($reservation),
                            'Grace period' => $graceMinutes . ' minutes'
                        ],
                        (string) $reservation['barcode_value'],
                        'If you cannot make it, cancel from your dashboard -- a cancellation costs you nothing, a no-show costs a warning.'
                    )
                );
            }

            $connection->query("UPDATE reservations SET reminder_sent_at = NOW() WHERE id = " . (int) $reservationId);
            $sentCount++;
        }

        return $sentCount;
    }
}

if (!function_exists('reservation_notifier_send_receipt')) {
    /** Emailed after the booth settles a transaction. */
    function reservation_notifier_send_receipt(mysqli $connection, int $reservationId): bool
    {
        try {
            if (!reservation_notifier_enabled($connection)) {
                return false;
            }

            $statement = $connection->prepare("
                SELECT
                    r.barcode_value,
                    r.parking_floor,
                    r.parking_slot,
                    COALESCE(NULLIF(TRIM(r.full_name), ''), u.full_name, 'Driver') AS full_name,
                    COALESCE(NULLIF(TRIM(r.email), ''), u.email, '') AS email,
                    r.user_id,
                    pt.actual_time_in,
                    pt.actual_time_out,
                    pt.total_hours_stayed,
                    pt.gross_amount,
                    pt.discount_type,
                    pt.discount_amount,
                    pt.total_payment,
                    pt.payment_method,
                    pt.payment_reference,
                    pt.paid_at
                FROM reservations r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
                WHERE r.id = ?
                LIMIT 1
            ");
            $statement->bind_param('i', $reservationId);
            $statement->execute();
            $record = $statement->get_result()->fetch_assoc();

            if (!$record || trim((string) $record['email']) === '') {
                return false;
            }

            $peso = static fn ($value): string => 'PHP ' . number_format((float) $value, 2);
            $rows = [
                'Slot' => trim(((string) $record['parking_floor']) . ' ' . ((string) $record['parking_slot'])),
                'Time in' => (string) ($record['actual_time_in'] ?? '--'),
                'Time out' => (string) ($record['actual_time_out'] ?? '--'),
                'Hours' => (string) (float) ($record['total_hours_stayed'] ?? 0),
                'Subtotal' => $peso($record['gross_amount'] ?? $record['total_payment'] ?? 0),
                'Total paid' => $peso($record['total_payment'] ?? 0),
                'Paid via' => (string) ($record['payment_method'] ?? 'Cash')
            ];

            if ((float) ($record['discount_amount'] ?? 0) > 0) {
                $rows['Discount (' . (string) $record['discount_type'] . ')'] = '-' . $peso($record['discount_amount']);
            }

            if (trim((string) ($record['payment_reference'] ?? '')) !== '') {
                $rows['Reference'] = (string) $record['payment_reference'];
            }

            reservation_notifier_add_inapp(
                $connection,
                isset($record['user_id']) ? (int) $record['user_id'] : null,
                $reservationId,
                'Payment received',
                'We received ' . $peso($record['total_payment'] ?? 0) . ' for barcode '
                    . (string) $record['barcode_value'] . '. Thank you for parking with us.'
            );

            return sndra_mail_send(
                (string) $record['email'],
                'Your SNDRA Park receipt',
                sndra_mail_layout(
                    'Payment received',
                    'Hello ' . (string) $record['full_name'] . ', here is your parking receipt.',
                    $rows,
                    (string) $record['barcode_value'],
                    'Keep this email as proof of payment.'
                )
            );
        } catch (Throwable $exception) {
            error_log('[reservation-notifier] receipt failed: ' . $exception->getMessage());
            return false;
        }
    }
}
