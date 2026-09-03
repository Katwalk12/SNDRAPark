<?php

require_once __DIR__ . '/../middleware/ValidationMiddleware.php';
require_once __DIR__ . '/../middleware/RequestValidationMiddleware.php';
require_once __DIR__ . '/../middleware/RBACMiddleware.php';
require_once __DIR__ . '/../middleware/RateLimiter.php';
require_once __DIR__ . '/../middleware/AuditLogger.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Vehicle.php';
require_once __DIR__ . '/../common/reservation-security.php';
require_once __DIR__ . '/../utils/ResponseHelper.php';
require_once __DIR__ . '/../utils/SessionManager.php';
require_once __DIR__ . '/../utils/PasswordPolicy.php';
require_once __DIR__ . '/../utils/LoginThrottle.php';

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

        if (strlen($password) > PasswordPolicy::MAX_LENGTH) {
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
            throw new RuntimeException('Too many login attempts from this device. Please try again later.', 429);
        }

        // Find user
        $user = $this->userModel->findByEmail($email);

        if (!$user) {
            AuditLogger::log('login_failed', 'auth', 'warning',
                'Login failed - invalid credentials', [
                    'email' => $email,
                    'ip_address' => $ip,
                    'reason' => 'invalid_credentials'
                ]);
            throw new RuntimeException('Invalid username or password.', 401);
        }

        // Strategy A: Block manual login for accounts provisioned via Google with no password set.
        // Detect Google-linked account when `google_id` exists and `password_hash` is empty.
        $googleId = isset($user['google_id']) ? trim((string) $user['google_id']) : '';
        $passwordHash = isset($user['password_hash']) ? (string) $user['password_hash'] : '';

        if ($passwordHash === '' && $googleId !== '') {
            AuditLogger::log('login_blocked_google_account', 'auth', 'warning',
                'Manual login blocked - account registered via Google', [
                    'email' => $email,
                    'user_id' => $user['id'] ?? null,
                    'ip_address' => $ip
                ]);

            ResponseHelper::json([
                'success' => false,
                'message' => "This account was registered via Google. Please click 'Continue with Google' to log in."
            ], 403);

            return;
        }

        // Too many unsuccessful attempts? Refuse before even looking at the password.
        $lockState = LoginThrottle::lockState($user);

        if ($lockState['locked']) {
            AuditLogger::log('login_locked_out', 'auth', 'warning',
                'Login attempt while temporarily locked out', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $ip,
                    'minutes_remaining' => $lockState['minutes_remaining']
                ]);
            throw new RuntimeException($lockState['message'], 423);
        }

        // Verify provided password against stored hash (safe because hash is non-empty here)
        if (!password_verify($password, $passwordHash)) {
            $failure = LoginThrottle::registerFailure((int) $user['id'], $user);

            AuditLogger::log($failure['locked'] ? 'account_locked' : 'login_failed', 'auth', 'warning',
                $failure['locked']
                    ? 'Account temporarily locked after too many failed logins'
                    : 'Login failed - invalid credentials', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $ip,
                    'failed_attempts' => $failure['attempts'],
                    'reason' => 'invalid_credentials'
                ]);

            throw new RuntimeException($failure['message'], $failure['locked'] ? 423 : 401);
        }

        // Successful password check clears the failed attempt streak.
        LoginThrottle::clearFailures((int) $user['id']);

        // Check if account is locked by an administrator / reservation violations
        if (isset($user['account_locked_until']) && strtotime((string) $user['account_locked_until']) > time()) {
            AuditLogger::log('account_locked_login_attempt', 'auth', 'warning',
                'Login attempt on locked account', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip_address' => $ip
                ]);
            throw new RuntimeException('Account is temporarily locked. Please try again later or contact the system administrator.', 423);
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
        $passwordStatus = PasswordPolicy::expiryStatus($user['password_changed_at'] ?? null);
        $responseData = [
            'user' => $this->sanitizeUser($user),
            'session' => $sessionData,
            'role' => $sessionData['user_role'] ?? 'user',
            'password_status' => $passwordStatus,
            'redirect' => 'user-dashboard.html'
        ];

        ResponseHelper::json([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => 'user-dashboard.html',
            'role' => $sessionData['user_role'] ?? 'user',
            'user' => $this->sanitizeUser($user),
            'session' => $sessionData,
            'password_status' => $passwordStatus,
            'notice' => $passwordStatus['message'],
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
            'email'          => $validatedData['email'],
            'password_hash'  => password_hash($validatedData['password'], PASSWORD_DEFAULT),
            'first_name'     => $validatedData['first_name'] ?? null,
            'last_name'      => $validatedData['last_name'] ?? null,
            'full_name'      => trim(($validatedData['first_name'] ?? '') . ' ' . ($validatedData['last_name'] ?? '')),
            'birth_date'     => $validatedData['birth_date'] ?? null,
            'vehicle_type'   => $validatedData['vehicle_type'] ?? null,
            'plate_number'   => $validatedData['plate_number'] ?? null,
            'vehicle_brand'  => $validatedData['vehicle_brand'] ?? null,
            'vehicle_model'  => $validatedData['vehicle_model'] ?? null,
            'vehicle_color'  => $validatedData['vehicle_color'] ?? null,
            'role'           => 'user'
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

        $vehicleModel = new Vehicle();
        $vehicleModel->create($userId, [
            'vehicle_type' => $validatedData['vehicle_type'],
            'plate_number' => $validatedData['plate_number'],
            'brand' => $validatedData['vehicle_brand'] ?? '',
            'model' => $validatedData['vehicle_model'] ?? '',
            'color' => $validatedData['vehicle_color'] ?? ''
        ]);

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
            $userRecord = $this->userModel->findById($sessionData['user_id']);
            $user = $this->sanitizeUser($userRecord);
            $passwordStatus = PasswordPolicy::expiryStatus($userRecord['password_changed_at'] ?? null);
            $responseData = [
                'user' => $user,
                'session' => $sessionData,
                'role' => $sessionData['user_role'] ?? 'user',
                'password_status' => $passwordStatus,
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
                'password_status' => $passwordStatus,
                'idle_timeout_seconds' => SessionManager::getSessionTimeout(),
                'data' => $responseData
            ]);
        } catch (Exception $e) {
            ResponseHelper::json([
                'success' => false,
                'authenticated' => false,
                'message' => 'No active session',
                'data' => [
                    'authenticated' => false
                ]
            ]);
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

        // The password hash is not exposed by findById, so read it from the auth record.
        $authRecord = $this->userModel->findByEmail($user['email']);
        $currentHash = (string) ($authRecord['password_hash'] ?? '');

        // Verify current password
        if ($currentHash === '' || !password_verify($data['current_password'], $currentHash)) {
            AuditLogger::log('password_change_wrong_current', 'auth', 'warning',
                'Password change failed - wrong current password', [
                    'user_id' => $userId,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            throw new RuntimeException('Current password is incorrect.', 400);
        }

        // Validate new password against the full policy, including the user's own details
        $newPassword = ValidationMiddleware::validatePassword(
            (string) $data['new_password'],
            true,
            PasswordPolicy::contextFromUser($user)
        );

        if (password_verify($newPassword, $currentHash)) {
            throw new RuntimeException('New password must be different from your current password.', 422);
        }

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
            'id'             => $user['id'],
            'email'          => $user['email'],
            'full_name'      => $user['full_name'],
            'first_name'     => $user['first_name'] ?? null,
            'last_name'      => $user['last_name'] ?? null,
            'birth_date'     => $user['birth_date'] ?? null,
            'vehicle_type'   => $user['vehicle_type'] ?? null,
            'plate_number'   => $user['plate_number'] ?? null,
            'vehicle_brand'  => $user['vehicle_brand'] ?? null,
            'vehicle_model'  => $user['vehicle_model'] ?? null,
            'vehicle_color'  => $user['vehicle_color'] ?? null,
            'role'           => $user['role'] ?? 'user',
            'created_at'     => $user['created_at'] ?? null
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
