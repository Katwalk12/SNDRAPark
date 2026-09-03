<?php
// update_status.php
// Lightweight endpoint to set reservation or slot status (used by booth / admin tools).
declare(strict_types=1);
require_once __DIR__ . '/../backend/config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? null; // 'reservation' or 'slot'

if (!$type) {
    booth_error('Missing type', 400);
}

$conn = booth_db();

try {
    if ($type === 'reservation') {
        $id = (int) ($input['id'] ?? 0);
        $status = trim((string) ($input['status'] ?? ''));
        if ($id <= 0 || $status === '') booth_error('Missing id or status', 400);
        $stmt = $conn->prepare("UPDATE reservations SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        booth_success('Reservation status updated', ['id' => $id, 'status' => $status]);
    }

    if ($type === 'slot') {
        $slot = trim((string) ($input['slot_code'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));
        if ($slot === '' || $status === '') booth_error('Missing slot_code or status', 400);
        $stmt = $conn->prepare("UPDATE parking_slots SET status = ?, updated_at = NOW() WHERE slot_code = ? LIMIT 1");
        $stmt->bind_param('ss', $status, $slot);
        $stmt->execute();
        booth_success('Parking slot status updated', ['slot_code' => $slot, 'status' => $status]);
    }

    booth_error('Invalid type', 400);

} catch (Throwable $ex) {
    booth_log('update_status_error', ['error' => $ex->getMessage()]);
    booth_error('Unable to update status', 500);
}
