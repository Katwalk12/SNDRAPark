<?php

declare(strict_types=1);

require_once __DIR__ . '/../parking/common.php';
require_once __DIR__ . '/../common/reservation-notifier.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

parking_bootstrap_endpoint('POST');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    booth_error('Method not allowed.', 405);
}

try {
    $payload = parking_request_data();

    // The booking is always for whoever owns the session cookie.
    //
    // This used to fall back to a userId in the request body when the session
    // was empty, which meant an unauthenticated caller could post any user's
    // id and book a slot in their name -- filling the garage with reservations
    // the named driver never made, and, because an unclaimed booking expires
    // into a no-show strike, walking a stranger's account toward the four-
    // strike lock in reservation-security.php. It is the same class of defect
    // the March audit found in ReservationController, where user_id came from
    // the query string; that one was fixed and this one was missed.
    //
    // Any client-supplied id is ignored rather than trusted.
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        booth_error('No active user session found.', 401);
    }

    $connection = booth_db();
    $reservation = parking_create_reservation($connection, [
        'user_id' => $userId,
        'vehicle_id' => $payload['vehicleId'] ?? $payload['vehicle_id'] ?? null,
        'parking_floor' => $payload['parkingFloor'] ?? $payload['parking_floor'] ?? '',
        'parking_slot' => $payload['parkingSlot'] ?? $payload['parking_slot'] ?? '',
        'full_name' => $payload['fullName'] ?? $payload['full_name'] ?? '',
        'email' => $payload['email'] ?? '',
        'reservation_date' => $payload['reservationDate'] ?? $payload['reservation_date'] ?? '',
        'reserved_time_in' => $payload['reservedTimeIn'] ?? $payload['reserved_time_in'] ?? '',
        'reservation_fee' => $payload['reservationFee'] ?? $payload['reservation_fee'] ?? null
    ]);

    // Best effort: a driver who is about to be held to a grace period should
    // be told what they booked and what code to present. A mail failure must
    // never fail the booking itself.
    $createdReservationId = (int) ($reservation['reservation_id'] ?? $reservation['reservationId'] ?? 0);

    if ($createdReservationId > 0) {
        reservation_notifier_send_confirmation($connection, $createdReservationId);
    }

    booth_success('Reservation created successfully.', $reservation, 201);
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();

    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    booth_log('user-submit-reservation-failed', [
        'error' => $exception->getMessage(),
        'status' => $status
    ]);

    // 409 used to be flattened to a single "slot taken" line, which hid every
    // other conflict the creation path reports. The messages it throws are
    // already written for the driver, so pass them through.
    booth_error(
        $exception->getMessage(),
        $status,
        $status >= 500 ? ['details' => $exception->getMessage()] : []
    );
}
