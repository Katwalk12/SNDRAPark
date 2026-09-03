<?php

require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Vehicle.php';
require_once __DIR__ . '/../common/reservation-security.php';
require_once __DIR__ . '/../utils/RequestHelper.php';
require_once __DIR__ . '/../utils/ResponseHelper.php';
require_once __DIR__ . '/../utils/PasswordPolicy.php';

class UserController
{
    private $userModel;
    private $vehicleModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->vehicleModel = new Vehicle();
    }

    public function getProfile()
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $connection = Database::connection();

        reservation_security_expire_due_reservations($connection, $userId);
        reservation_security_sync_user_account($connection, $userId);

        $user = $this->userModel->findById($userId);

        if (!$user) {
            ResponseHelper::error('User not found.', 404);
        }

        ResponseHelper::success('User profile retrieved successfully.', $user);
    }

    public function updateProfile($data)
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $connection = Database::connection();

        reservation_security_sync_user_account($connection, $userId);

        ValidationMiddleware::validateRequired($data, ['full_name', 'email']);
        $fullName     = trim((string) $data['full_name']);
        $email        = trim((string) $data['email']);
        $birthDate    = isset($data['birth_date']) ? trim((string) $data['birth_date']) : null;
        $password     = isset($data['password']) ? (string) $data['password'] : '';
        $vehicleType  = isset($data['vehicle_type']) ? trim((string) $data['vehicle_type']) : null;
        $plateNumber  = isset($data['plate_number']) ? trim((string) $data['plate_number']) : null;
        $vehicleBrand = isset($data['vehicle_brand']) ? trim((string) $data['vehicle_brand']) : null;
        $vehicleModel = isset($data['vehicle_model']) ? trim((string) $data['vehicle_model']) : null;
        $vehicleColor = isset($data['vehicle_color']) ? trim((string) $data['vehicle_color']) : null;

        if ($fullName === '') {
            ResponseHelper::error('Full name is required.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHelper::error('Enter a valid email address.', 422);
        }

        $existingUser = $this->userModel->findByEmail($email);
        if ($existingUser && (int) $existingUser['id'] !== $userId) {
            ResponseHelper::error('Email already exists.', 409);
        }

        if ($birthDate !== null && $birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
            ResponseHelper::error('Enter a valid birth date.', 422);
        }

        // Changing the password from the profile uses the same policy as sign up.
        if ($password !== '') {
            $policyErrors = PasswordPolicy::check($password, [
                'full_name'  => $fullName,
                'email'      => $email,
                'birth_date' => $birthDate,
            ]);

            if (!empty($policyErrors)) {
                ResponseHelper::error($policyErrors[0], 422, $policyErrors);
            }
        }

        // Validate vehicle_type if provided
        if ($vehicleType !== null && $vehicleType !== '' && !in_array($vehicleType, ['Motorcycle', 'Car'], true)) {
            ResponseHelper::error('Vehicle type must be Motorcycle or Car.', 422);
        }

        // Validate plate_number if provided
        if ($plateNumber !== null && $plateNumber !== '' && !preg_match('/^[A-Za-z0-9\-]{1,20}$/', $plateNumber)) {
            ResponseHelper::error('Plate number contains invalid characters or is too long.', 422);
        }

        $this->userModel->update(
            $userId, $fullName, $email, $birthDate, $password,
            $vehicleType, $plateNumber, $vehicleBrand, $vehicleModel, $vehicleColor
        );
        $updatedUser = $this->userModel->findById($userId);

        $_SESSION['user_email'] = $updatedUser['email'] ?? $email;
        $_SESSION['user_name']  = $updatedUser['full_name'] ?? $fullName;

        ResponseHelper::success('User profile updated successfully.', [
            'user' => $updatedUser
        ]);
    }

    public function getVehicles()
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            ResponseHelper::error('Unauthorized: User session required.', 401);
        }

        ResponseHelper::success('Vehicles retrieved successfully.', [
            'vehicles' => $this->vehicleModel->findByUserId($userId)
        ]);
    }

    public function addVehicle($data)
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            ResponseHelper::error('Unauthorized: User session required.', 401);
        }

        try {
            $vehicleId = $this->vehicleModel->create($userId, $data);
            $vehicle = $this->vehicleModel->findForUser($vehicleId, $userId);

            $user = $this->userModel->findById($userId);
            if ($user && empty($user['plate_number'])) {
                $this->userModel->update(
                    $userId,
                    $user['full_name'] ?? '',
                    $user['email'] ?? '',
                    $user['birth_date'] ?? null,
                    '',
                    $vehicle['vehicle_type'] ?? null,
                    $vehicle['plate_number'] ?? null,
                    $vehicle['brand'] ?? null,
                    $vehicle['model'] ?? null,
                    $vehicle['color'] ?? null
                );
            }

            ResponseHelper::success('Vehicle saved successfully.', [
                'vehicle' => $vehicle
            ], 201);
        } catch (InvalidArgumentException $exception) {
            ResponseHelper::error($exception->getMessage(), (int) $exception->getCode() ?: 422);
        } catch (RuntimeException $exception) {
            $status = (int) $exception->getCode();
            ResponseHelper::error($exception->getMessage(), ($status >= 400 && $status <= 599) ? $status : 500);
        }
    }

    public function updateVehicle(array $data)
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            ResponseHelper::error('Unauthorized: User session required.', 401);
        }

        $vehicleId = $this->resolveVehicleId($data);
        if ($vehicleId <= 0) {
            ResponseHelper::error('Vehicle ID is required for update.', 422);
        }

        $vehicle = $this->vehicleModel->findForUser($vehicleId, $userId);
        if (!$vehicle) {
            ResponseHelper::error('Vehicle not found.', 404);
        }

        try {
            $validated = Vehicle::validateVehicleData($data);
            if ($this->vehicleModel->plateExistsForUser($userId, $validated['plate_number'], $vehicleId)) {
                throw new RuntimeException('This plate number is already registered on your account.', 409);
            }

            $this->vehicleModel->updateForUser($vehicleId, $userId, $validated);
            $updatedVehicle = $this->vehicleModel->findForUser($vehicleId, $userId);

            ResponseHelper::success('Vehicle updated successfully.', [
                'vehicle' => $updatedVehicle
            ]);
        } catch (InvalidArgumentException $exception) {
            ResponseHelper::error($exception->getMessage(), (int) $exception->getCode() ?: 422);
        } catch (RuntimeException $exception) {
            $status = (int) $exception->getCode();
            ResponseHelper::error($exception->getMessage(), ($status >= 400 && $status <= 599) ? $status : 500);
        }
    }

    public function removeVehicle(array $data)
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            ResponseHelper::error('Unauthorized: User session required.', 401);
        }

        $vehicleId = $this->resolveVehicleId($data);
        if ($vehicleId <= 0) {
            ResponseHelper::error('Vehicle ID is required for delete.', 422);
        }

        $vehicle = $this->vehicleModel->findForUser($vehicleId, $userId);
        if (!$vehicle) {
            ResponseHelper::error('Vehicle not found.', 404);
        }

        if (!$this->vehicleModel->deleteForUser($vehicleId, $userId)) {
            ResponseHelper::error('Failed to remove vehicle.', 500);
        }

        ResponseHelper::success('Vehicle removed successfully.', []);
    }

    private function resolveVehicleId(array $data): int
    {
        $vehicleId = 0;

        if (isset($data['vehicle_id'])) {
            $vehicleId = (int) $data['vehicle_id'];
        }

        if ($vehicleId <= 0 && isset($data['vehicleId'])) {
            $vehicleId = (int) $data['vehicleId'];
        }

        if ($vehicleId <= 0 && isset($_GET['id'])) {
            $vehicleId = (int) $_GET['id'];
        }

        return $vehicleId;
    }
}

