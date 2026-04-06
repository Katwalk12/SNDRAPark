<?php

require_once __DIR__ . '/../config/database.php';

class Reservation
{
    public function create($userId, $parkingSlotId, $reservationDate)
    {
        $connection = Database::connection();
        $statement = $connection->prepare('INSERT INTO reservations (user_id, parking_slot_id, reservation_date) VALUES (?, ?, ?)');
        $statement->bind_param('iis', $userId, $parkingSlotId, $reservationDate);
        $statement->execute();

        return $connection->insert_id;
    }

    public function findByUserId($userId)
    {
        $connection = Database::connection();
        $statement = $connection->prepare('
            SELECT r.id, r.reservation_date, r.status, p.slot_code
            FROM reservations r
            INNER JOIN parking_slots p ON p.id = r.parking_slot_id
            WHERE r.user_id = ?
            ORDER BY r.reservation_date DESC
        ');
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
