<?php

require_once __DIR__ . '/../config/database.php';

class ParkingSlot
{
    public function getAvailableSlots()
    {
        $connection = Database::connection();
        $result = $connection->query("SELECT id, slot_code, status FROM parking_slots WHERE status = 'available' ORDER BY slot_code ASC");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function findById($id)
    {
        $connection = Database::connection();
        $statement = $connection->prepare('SELECT id, slot_code, status FROM parking_slots WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $id);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_assoc();
    }

    public function updateStatus($id, $status)
    {
        $connection = Database::connection();
        $statement = $connection->prepare('UPDATE parking_slots SET status = ? WHERE id = ?');
        $statement->bind_param('si', $status, $id);
        $statement->execute();

        return $statement->affected_rows >= 0;
    }
}
