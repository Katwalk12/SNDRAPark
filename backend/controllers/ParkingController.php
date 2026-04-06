<?php

require_once __DIR__ . '/../models/ParkingSlot.php';
require_once __DIR__ . '/../utils/ResponseHelper.php';

class ParkingController
{
    private $parkingSlotModel;

    public function __construct()
    {
        $this->parkingSlotModel = new ParkingSlot();
    }

    public function getAvailableSlots()
    {
        $slots = $this->parkingSlotModel->getAvailableSlots();
        ResponseHelper::success('Available parking slots retrieved successfully.', $slots);
    }
}
