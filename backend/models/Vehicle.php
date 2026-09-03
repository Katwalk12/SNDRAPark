<?php

require_once __DIR__ . '/../config/database.php';

class Vehicle
{
    public static function normalizePlateNumber(string $plateNumber): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($plateNumber)));
    }

    public static function validateVehicleData(array $data): array
    {
        $vehicleType = trim((string) ($data['vehicle_type'] ?? $data['vehicleType'] ?? ''));
        $plateNumber = self::normalizePlateNumber((string) ($data['plate_number'] ?? $data['plateNumber'] ?? ''));
        $brand = trim((string) ($data['brand'] ?? $data['vehicle_brand'] ?? $data['vehicleBrand'] ?? ''));
        $model = trim((string) ($data['model'] ?? $data['vehicle_model'] ?? $data['vehicleModel'] ?? ''));
        $color = trim((string) ($data['color'] ?? $data['vehicle_color'] ?? $data['vehicleColor'] ?? ''));

        if (!in_array($vehicleType, ['Car', 'Motorcycle'], true)) {
            throw new InvalidArgumentException('Vehicle type must be Car or Motorcycle.', 422);
        }

        if ($plateNumber === '') {
            throw new InvalidArgumentException('Plate number is required.', 422);
        }

        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $plateNumber)) {
            throw new InvalidArgumentException('Plate number must be 2-20 letters/numbers. Hyphens are allowed.', 422);
        }

        foreach (['Vehicle brand' => $brand, 'Vehicle model' => $model, 'Vehicle color' => $color] as $label => $value) {
            if ($value !== '' && strlen($value) > 100) {
                throw new InvalidArgumentException("{$label} is too long.", 422);
            }
        }

        return [
            'vehicle_type' => $vehicleType,
            'plate_number' => $plateNumber,
            'brand' => $brand,
            'model' => $model,
            'color' => $color
        ];
    }

    public function findByUserId(int $userId): array
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'SELECT vehicle_id, user_id, vehicle_type, plate_number, brand, model, color, created_at
             FROM vehicles
             WHERE user_id = ?
             ORDER BY created_at DESC, vehicle_id DESC'
        );
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function findForUser(int $vehicleId, int $userId): ?array
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'SELECT vehicle_id, user_id, vehicle_type, plate_number, brand, model, color, created_at
             FROM vehicles
             WHERE vehicle_id = ? AND user_id = ?
             LIMIT 1'
        );
        $statement->bind_param('ii', $vehicleId, $userId);
        $statement->execute();
        $result = $statement->get_result();
        $vehicle = $result->fetch_assoc();

        return $vehicle ?: null;
    }

    public function create(int $userId, array $data): int
    {
        $validated = self::validateVehicleData($data);
        $connection = Database::connection();

        if ($this->plateExistsForUser($userId, $validated['plate_number'])) {
            throw new RuntimeException('This vehicle is already registered on your account.', 409);
        }

        $statement = $connection->prepare(
            'INSERT INTO vehicles (user_id, vehicle_type, plate_number, brand, model, color)
             VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))'
        );
        $statement->bind_param(
            'isssss',
            $userId,
            $validated['vehicle_type'],
            $validated['plate_number'],
            $validated['brand'],
            $validated['model'],
            $validated['color']
        );
        $statement->execute();

        return (int) $connection->insert_id;
    }

    public function updateForUser(int $vehicleId, int $userId, array $data): bool
    {
        $validated = self::validateVehicleData($data);
        $connection = Database::connection();

        $statement = $connection->prepare(
            'UPDATE vehicles
             SET vehicle_type = ?, plate_number = ?, brand = NULLIF(?, \'\'), model = NULLIF(?, \'\'), color = NULLIF(?, \'\')
             WHERE vehicle_id = ? AND user_id = ?'
        );
        $statement->bind_param(
            'ssssssi',
            $validated['vehicle_type'],
            $validated['plate_number'],
            $validated['brand'],
            $validated['model'],
            $validated['color'],
            $vehicleId,
            $userId
        );

        return $statement->execute();
    }

    public function deleteForUser(int $vehicleId, int $userId): bool
    {
        $connection = Database::connection();
        $statement = $connection->prepare(
            'DELETE FROM vehicles WHERE vehicle_id = ? AND user_id = ?'
        );
        $statement->bind_param('ii', $vehicleId, $userId);

        return $statement->execute();
    }

    private function plateExistsForUser(int $userId, string $plateNumber, int $excludeVehicleId = 0): bool
    {
        $connection = Database::connection();
        $query = 'SELECT vehicle_id FROM vehicles WHERE user_id = ? AND plate_number = ?';
        if ($excludeVehicleId > 0) {
            $query .= ' AND vehicle_id != ?';
        }
        $query .= ' LIMIT 1';

        $statement = $connection->prepare($query);
        if ($excludeVehicleId > 0) {
            $statement->bind_param('isi', $userId, $plateNumber, $excludeVehicleId);
        } else {
            $statement->bind_param('is', $userId, $plateNumber);
        }
        $statement->execute();

        return (bool) $statement->get_result()->fetch_assoc();
    }
}
