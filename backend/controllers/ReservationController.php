<?php

require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/../models/ParkingSlot.php';
require_once __DIR__ . '/../models/Reservation.php';
require_once __DIR__ . '/../utils/RequestHelper.php';
require_once __DIR__ . '/../utils/ResponseHelper.php';
require_once __DIR__ . '/../parking/common.php';

class ReservationController
{
    private $reservationModel;
    private $parkingSlotModel;

    public function __construct()
    {
        $this->reservationModel = new Reservation();
        $this->parkingSlotModel = new ParkingSlot();
    }

    public function getReservations()
    {
        // SECURITY: Use session user_id, NOT request parameter
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::error('Unauthorized: User session required.', 401);
        }

        $userId = (int) $_SESSION['user_id'];
        $reservations = $this->reservationModel->findByUserId($userId);
        ResponseHelper::success('Reservations retrieved successfully.', $reservations);
    }

    public function createReservation($data)
    {
        // SECURITY: Use session user_id, NOT from request data
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::error('Unauthorized: User session required.', 401);
        }

        $userId = (int) $_SESSION['user_id'];

        // Same one-hold-at-a-time rule the dashboard path enforces; without it
        // this route would be a way around the limit.
        try {
            parking_assert_single_active_reservation(Database::connection(), $userId);
        } catch (RuntimeException $exception) {
            ResponseHelper::error($exception->getMessage(), 409);
        }

        ValidationMiddleware::validateRequired($data, ['parking_slot_id', 'reservation_date']);

        $slot = $this->parkingSlotModel->findById((int) $data['parking_slot_id']);

        if (!$slot) {
            ResponseHelper::error('Parking slot not found.', 404);
        }

        if ($slot['status'] !== 'available') {
            ResponseHelper::error('Parking slot is not available.', 409);
        }

        $reservationId = $this->reservationModel->create(
            $userId,
            (int) $data['parking_slot_id'],
            $data['reservation_date']
        );

        $this->parkingSlotModel->updateStatus((int) $data['parking_slot_id'], 'reserved');

        ResponseHelper::success('Reservation created successfully.', ['reservation_id' => $reservationId], 201);
    }
}
