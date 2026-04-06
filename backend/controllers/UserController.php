<?php

require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../common/reservation-security.php';
require_once __DIR__ . '/../utils/RequestHelper.php';
require_once __DIR__ . '/../utils/ResponseHelper.php';

class UserController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
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
        $fullName = trim((string) $data['full_name']);
        $email = trim((string) $data['email']);
        $birthDate = isset($data['birth_date']) ? trim((string) $data['birth_date']) : null;
        $password = isset($data['password']) ? (string) $data['password'] : '';

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

        if ($password !== '' && !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/', $password)) {
            ResponseHelper::error('Password must contain upper, lower, number, symbol, and at least 8 characters.', 422);
        }

        $this->userModel->update($userId, $fullName, $email, $birthDate, $password);
        $updatedUser = $this->userModel->findById($userId);

        $_SESSION['user_email'] = $updatedUser['email'] ?? $email;
        $_SESSION['user_name'] = $updatedUser['full_name'] ?? $fullName;

        ResponseHelper::success('User profile updated successfully.', [
            'user' => $updatedUser
        ]);
    }
}
