<?php

require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/../middleware/RequestValidationMiddleware.php';
require_once __DIR__ . '/../middleware/RBACMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../middleware/AuditLogger.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../common/reservation-security.php';
require_once __DIR__ . '/../utils/ResponseHelper.php';
require_once __DIR__ . '/../utils/SessionManager.php';

class AuthController
{
    private $userModel;

    public function __construct()
    {
        $this->userModel = new User();
    }

    public function login($data)
    {
        // Validate request comprehensively
        RequestValidationMiddleware::validateRequest();

        // Get validated request data
        $data = RequestValidationMiddleware::validateApiRequest(['email', 'password']);

        // Validate and sanitize input
        $email = ValidationMiddleware::validateEmail($data['email']);
        $password = (string) ($data['password'] ?? '');

        if ($password === '') {
            throw new InvalidArgumentException('Password is required.', 422);
        }

        if (strlen($password) > 128) {
            throw new InvalidArgumentException('Password is too long.', 422);
        }

        // Check rate limiting for login attempts
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!RateLimiter::check('login', $ip, $email)) {
            AuditLogger::log('login_rate_limit', 'auth', 'warning',
                'Login rate limit exceeded', [
                    'ip_address' => $ip,
                    'email' => $email
                ]);
            throw new RuntimeException('Too many login attempts. Please try again later.', 429);
        }

        // Find user
        $user = $this->userModel->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Record failed attempt (rate limiter automatically records on check)
            AuditLogger::log('login_failed', 'auth', 'warning',
                'Login failed - invalid credentials', [
                    'email' => $email,
                    'ip_address' => $ip,
                    'reason' => 'invalid_credentials'
                ]);
            throw new RuntimeException('Invalid email or password.', 401);
        }

        // Check if account is locked
        if (isset($user['account_locked_until']) && strtotime($user['account_locked_until']) > time()) {
            AuditLogger::log('account_locked_login_attempt', 'auth', 'warning',
                'Login attempt on locked account', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $ip
                ]);
            throw new RuntimeException('Account is temporarily locked. Please try again later.', 423);
        }

        // Validate user can login (existing business logic)
        $connection = Database::connection();
        reservation_security_expire_due_reservations($connection, (int) $user['id']);
        $user = reservation_security_assert_user_can_login($connection, (int) $user['id']);
        $user = $this->userModel->findById((int) $user['id']) ?: $user;

        // Create secure session
        SessionManager::createSession($user);

        // Record successful login
        AuditLogger::log('login_success', 'auth', 'success',
            'User logged in successfully', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip_address' => $ip,
                'user_role' => $user['role'] ?? 'user'
            ]);

        $sessionData = SessionManager::getSessionData();
        $responseData = [
            'user' => $this->sanitizeUser($user),
            'session' => $sessionData,
            'role' => $sessionData['user_role'] ?? 'user',
            'redirect' => 'user-dashboard.html'
        ];

        ResponseHelper::json([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => 'user-dashboard.html',
            'role' => $sessionData['user_role'] ?? 'user',
            'user' => $this->sanitizeUser($user),
            'session' => $sessionData,
            'data' => $responseData
        ]);
    }

    public function register($data)
    {
        // Validate request comprehensively
        RequestValidationMiddleware::validateRequest();

        // Get validated request data
        $data = RequestValidationMiddleware::validateApiRequest(['email', 'password']);

        // Validate registration data
        $validatedData = ValidationMiddleware::validateUserRegistration($data);

        // Check if email already exists
        if ($this->userModel->findByEmail($validatedData['email'])) {
            AuditLogger::log('duplicate_email_registration', 'auth', 'warning',
                'Attempted registration with existing email', [
                    'email' => $validatedData['email'],
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            throw new RuntimeException('Email already registered.', 409);
        }

        // Create user
        $userId = $this->userModel->create([
            'email' => $validatedData['email'],
            'password_hash' => password_hash($validatedData['password'], PASSWORD_DEFAULT),
            'first_name' => $validatedData['first_name'] ?? null,
            'last_name' => $validatedData['last_name'] ?? null,
            'full_name' => trim(($validatedData['first_name'] ?? '') . ' ' . ($validatedData['last_name'] ?? '')),
            'birth_date' => $validatedData['birth_date'] ?? null,
            'role' => 'user' // Default role
        ]);

        if (!$userId) {
            AuditLogger::log('registration_failed', 'auth', 'error',
                'User registration failed', [
                    'email' => $validatedData['email'],
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'reason' => 'database_error'
                ]);
            throw new RuntimeException('Registration failed.', 500);
        }

        // Get created user
        $user = $this->userModel->findById($userId);

        // Create session for new user
        SessionManager::createSession($user);

        AuditLogger::log('registration_success', 'auth', 'success',
            'User registered successfully', [
                'email' => $validatedData['email'],
                'user_id' => $userId
            ]);

        $sessionData = SessionManager::getSessionData();
        $responseData = [
            'user' => $this->sanitizeUser($user),
            'session' => $sessionData,
            'role' => $sessionData['user_role'] ?? 'user',
            'redirect' => 'user-dashboard.html'
        ];

        ResponseHelper::json([
            'success' => true,
            'message' => 'Registration successful',
            'redirect' => 'user-dashboard.html',
            'role' => $sessionData['user_role'] ?? 'user',
            'user' => $this->sanitizeUser($user),
            'session' => $sessionData,
            'data' => $responseData
        ], 201);
    }

    public function logout()
    {
        // Validate request
        RequestValidationMiddleware::validateRequest();

        $userId = $_SESSION['user_id'] ?? null;

        // Destroy session
        SessionManager::destroySession();

        if ($userId) {
            AuditLogger::log('logout', 'auth', 'success',
                'User logged out', [
                    'user_id' => $userId,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
        }

        ResponseHelper::success('Logout successful.');
    }

    public function session()
    {
        // Validate request
        RequestValidationMiddleware::validateRequest();

        try {
            // Validate current session
            SessionManager::validateSession();

            $sessionData = SessionManager::getSessionData();
            $user = $this->sanitizeUser($this->userModel->findById($sessionData['user_id']));
            $responseData = [
                'user' => $user,
                'session' => $sessionData,
                'role' => $sessionData['user_role'] ?? 'user',
                'redirect' => 'user-dashboard.html'
            ];

            ResponseHelper::json([
                'success' => true,
                'authenticated' => true,
                'message' => 'Session valid',
                'role' => $sessionData['user_role'] ?? 'user',
                'redirect' => 'user-dashboard.html',
                'user' => $user,
                'session' => $sessionData,
                'data' => $responseData
            ]);
        } catch (Exception $e) {
            ResponseHelper::json([
                'success' => false,
                'authenticated' => false,
                'message' => 'No active session'
            ], 401);
        }
    }

    public function changePassword($data)
    {
        // Validate request
        RequestValidationMiddleware::validateRequest();

        // Require authentication
        RBACMiddleware::authorize('users.update');

        // Get validated request data
        $data = RequestValidationMiddleware::validateApiRequest(['current_password', 'new_password']);

        $userId = $_SESSION['user_id'];
        $user = $this->userModel->findById($userId);

        if (!$user) {
            throw new RuntimeException('User not found.', 404);
        }

        // Verify current password
        if (!password_verify($data['current_password'], $user['password_hash'])) {
            AuditLogger::log('password_change_wrong_current', 'auth', 'warning',
                'Password change failed - wrong current password', [
                    'user_id' => $userId,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            throw new RuntimeException('Current password is incorrect.', 400);
        }

        // Validate new password
        $newPassword = ValidationMiddleware::validatePassword($data['new_password']);

        // Update password
        $success = $this->userModel->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));

        if (!$success) {
            AuditLogger::log('password_change_failed', 'auth', 'error',
                'Password change failed', [
                    'user_id' => $userId,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'reason' => 'database_error'
                ]);
            throw new RuntimeException('Password change failed.', 500);
        }

        AuditLogger::log('password_change_success', 'auth', 'success',
            'Password changed successfully', [
                'user_id' => $userId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);

        ResponseHelper::success('Password changed successfully.');
    }

    private function sanitizeUser($user)
    {
        if (!$user) return null;

        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'birth_date' => $user['birth_date'] ?? null,
            'role' => $user['role'] ?? 'user',
            'created_at' => $user['created_at'] ?? null
        ];
    }

    private function createSessionForUser($user)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['full_name'] ?? '';
        $_SESSION['login_time'] = time();
    }

    private function clearSession()
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    private function parseBirthDate($birthDate)
    {
        $parsedBirthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $errors = DateTimeImmutable::getLastErrors();
        $hasFormatErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (!$parsedBirthDate || $hasFormatErrors) {
            ResponseHelper::error('Enter a valid birth date.', 422);
        }

        $today = new DateTimeImmutable('today');

        if ($parsedBirthDate > $today) {
            ResponseHelper::error('Enter a valid birth date.', 422);
        }

        return $parsedBirthDate;
    }

    private function calculateAge(DateTimeImmutable $birthDate)
    {
        $today = new DateTimeImmutable('today');
        return $birthDate->diff($today)->y;
    }
}
