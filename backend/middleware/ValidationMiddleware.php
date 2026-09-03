<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/PasswordPolicy.php';
require_once __DIR__ . '/AuditLogger.php';

class ValidationMiddleware
{
    // Security settings
    private const MAX_STRING_LENGTH = 1000;
    private const MAX_EMAIL_LENGTH = 150;

    /**
     * Validate required fields
     */
    public static function validateRequired($data, $fields)
    {
        $missing = [];

        foreach ($fields as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new InvalidArgumentException('Missing required fields: ' . implode(', ', $missing), 422);
        }
    }

    /**
     * Validate email format and security
     */
    public static function validateEmail(string $email, bool $required = true): string
    {
        $email = trim($email);

        if ($required && empty($email)) {
            throw new InvalidArgumentException('Email is required.', 422);
        }

        if (empty($email)) {
            return '';
        }

        // Check length
        if (strlen($email) > self::MAX_EMAIL_LENGTH) {
            throw new InvalidArgumentException('Email is too long.', 422);
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format.', 422);
        }

        // Additional security checks
        if (self::containsSuspiciousPatterns($email)) {
            AuditLogger::logSecurityEvent('suspicious_email', 'validation', 'warning',
                "Suspicious email pattern detected: {$email}");
            throw new InvalidArgumentException('Invalid email format.', 422);
        }

        return $email;
    }

    /**
     * Validate password strength and security.
     *
     * The rules themselves live in PasswordPolicy so registration, password change
     * and the forgot-password reset flow all enforce exactly the same policy.
     *
     * @param array $context Optional user details (first_name, last_name, email, birth_date)
     *                       used to reject easily guessed passwords.
     */
    public static function validatePassword(string $password, bool $required = true, array $context = []): string
    {
        return PasswordPolicy::validate($password, $context, $required);
    }

    /**
     * Validate string input with length and content checks
     */
    public static function validateString(string $value, string $fieldName, int $minLength = 0, ?int $maxLength = null): string
    {
        $value = trim($value);

        if ($maxLength === null) {
            $maxLength = self::MAX_STRING_LENGTH;
        }

        if (strlen($value) < $minLength) {
            throw new InvalidArgumentException("{$fieldName} must be at least {$minLength} characters long.", 422);
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$fieldName} is too long (maximum {$maxLength} characters).", 422);
        }

        // Check for potentially dangerous content
        if (self::containsMaliciousContent($value)) {
            AuditLogger::logSecurityEvent('malicious_content', 'validation', 'warning',
                "Potentially malicious content detected in {$fieldName}");
            throw new InvalidArgumentException("{$fieldName} contains invalid content.", 422);
        }

        return $value;
    }

    /**
     * Validate integer input
     */
    public static function validateInteger($value, string $fieldName, ?int $min = null, ?int $max = null): int
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("{$fieldName} must be a valid number.", 422);
        }

        $intValue = (int) $value;

        if ($min !== null && $intValue < $min) {
            throw new InvalidArgumentException("{$fieldName} must be at least {$min}.", 422);
        }

        if ($max !== null && $intValue > $max) {
            throw new InvalidArgumentException("{$fieldName} must be at most {$max}.", 422);
        }

        return $intValue;
    }

    /**
     * Validate date input
     */
    public static function validateDate(string $date, string $fieldName, string $format = 'Y-m-d'): string
    {
        $date = trim($date);

        if (empty($date)) {
            throw new InvalidArgumentException("{$fieldName} is required.", 422);
        }

        $dateTime = DateTime::createFromFormat($format, $date);
        if (!$dateTime || $dateTime->format($format) !== $date) {
            throw new InvalidArgumentException("{$fieldName} must be a valid date in format {$format}.", 422);
        }

        // Check if date is not in the future (for birth dates, etc.)
        if ($fieldName === 'birth_date' || strpos(strtolower($fieldName), 'birth') !== false) {
            $today = new DateTime();
            if ($dateTime > $today) {
                throw new InvalidArgumentException("{$fieldName} cannot be in the future.", 422);
            }

            // Check if person is not too old (reasonable limit)
            $age = $today->diff($dateTime)->y;
            if ($age > 150) {
                $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost';
                if ($isLocalhost) {
                    throw new InvalidArgumentException("{$fieldName} is not valid. Age {$age} calculated from '{$date}' is too high.", 422);
                }
                throw new InvalidArgumentException("{$fieldName} is not valid.", 422);
            }
        }

        return $date;
    }

    /**
     * Validate barcode format
     */
    public static function validateBarcode(string $barcode, string $fieldName = 'barcode'): string
    {
        $barcode = trim($barcode);

        if (empty($barcode)) {
            throw new InvalidArgumentException("{$fieldName} is required.", 422);
        }

        // Check length (assuming 8-20 characters for barcodes)
        if (strlen($barcode) < 8 || strlen($barcode) > 20) {
            throw new InvalidArgumentException("{$fieldName} must be between 8 and 20 characters.", 422);
        }

        // Check for valid characters (alphanumeric, hyphens, underscores)
        if (!preg_match('/^[A-Za-z0-9\-_]+$/', $barcode)) {
            throw new InvalidArgumentException("{$fieldName} contains invalid characters.", 422);
        }

        return $barcode;
    }

    /**
     * Validate phone number format
     */
    public static function validatePhone(string $phone, string $fieldName = 'phone'): string
    {
        $phone = trim($phone);

        if (empty($phone)) {
            return '';
        }

        // Remove all non-digit characters for validation
        $digitsOnly = preg_replace('/\D/', '', $phone);

        // Check length (10-15 digits for international numbers)
        if (strlen($digitsOnly) < 10 || strlen($digitsOnly) > 15) {
            throw new InvalidArgumentException("{$fieldName} must be a valid phone number.", 422);
        }

        return $phone;
    }

    /**
     * Validate user registration data
     */
    public static function validateUserRegistration(array $data): array
    {
        self::validateRequired($data, ['email', 'password', 'vehicleType', 'plateNumber']);

        $validated = [
            'email' => self::validateEmail($data['email'])
        ];

        if (isset($data['firstName'])) {
            $validated['first_name'] = self::validateString($data['firstName'], 'First name', 1, 100);
        }

        if (isset($data['lastName'])) {
            $validated['last_name'] = self::validateString($data['lastName'], 'Last name', 1, 100);
        }

        if (isset($data['birthDate'])) {
            $validated['birth_date'] = self::validateDate($data['birthDate'], 'Birth date');
        }

        // Validated last so the password can be checked against the name, email and birth date.
        $validated['password'] = self::validatePassword($data['password'], true, [
            'first_name' => $validated['first_name'] ?? null,
            'last_name'  => $validated['last_name'] ?? null,
            'email'      => $validated['email'],
            'birth_date' => $validated['birth_date'] ?? null,
        ]);

        // Validate vehicle type
        $vehicleType = trim((string) ($data['vehicleType'] ?? ''));
        if (!in_array($vehicleType, ['Motorcycle', 'Car'], true)) {
            throw new InvalidArgumentException('Vehicle type must be Motorcycle or Car.', 422);
        }
        $validated['vehicle_type'] = $vehicleType;

        // Validate plate number
        $plateNumber = strtoupper(trim((string) ($data['plateNumber'] ?? '')));
        if ($plateNumber === '') {
            throw new InvalidArgumentException('Plate number is required.', 422);
        }
        if (!preg_match('/^[A-Z0-9\-]{1,20}$/', $plateNumber)) {
            throw new InvalidArgumentException('Plate number must be alphanumeric (hyphens allowed, max 20 chars).', 422);
        }
        $validated['plate_number'] = $plateNumber;

        $optionalVehicleFields = [
            'vehicleBrand' => ['vehicle_brand', 'Vehicle brand', 100],
            'vehicleModel' => ['vehicle_model', 'Vehicle model', 100],
            'vehicleColor' => ['vehicle_color', 'Vehicle color', 50],
        ];

        foreach ($optionalVehicleFields as $requestKey => [$validatedKey, $label, $maxLength]) {
            if (isset($data[$requestKey]) && trim((string) $data[$requestKey]) !== '') {
                $validated[$validatedKey] = self::validateString((string) $data[$requestKey], $label, 1, $maxLength);
            }
        }

        return $validated;
    }

    /**
     * Validate reservation data
     */
    public static function validateReservation(array $data): array
    {
        self::validateRequired($data, ['parking_slot_id', 'reservation_date']);

        $validated = [
            'parking_slot_id' => self::validateInteger($data['parking_slot_id'], 'Parking slot ID', 1),
            'reservation_date' => self::validateDate($data['reservation_date'], 'Reservation date')
        ];

        if (isset($data['barcode_value'])) {
            $validated['barcode_value'] = self::validateBarcode($data['barcode_value']);
        }

        if (isset($data['parking_floor'])) {
            $validated['parking_floor'] = self::validateString($data['parking_floor'], 'Parking floor', 1, 50);
        }

        if (isset($data['parking_slot'])) {
            $validated['parking_slot'] = self::validateString($data['parking_slot'], 'Parking slot', 1, 50);
        }

        return $validated;
    }

    /**
     * Fields that must keep their exact value. Credentials are hashed or compared,
     * never rendered, so HTML-escaping them would silently rewrite any password
     * containing & < > " ' and break the login/reset flows against each other.
     */
    private const RAW_INPUT_KEYS = [
        'password',
        'new_password',
        'newpassword',
        'current_password',
        'currentpassword',
        'confirm_password',
        'confirmpassword',
        'password_confirmation',
        'otp',
        'pin',
    ];

    /**
     * Sanitize input data to prevent XSS
     */
    public static function sanitizeInput($data, ?string $key = null)
    {
        if (is_array($data)) {
            $sanitized = [];

            foreach ($data as $itemKey => $value) {
                $sanitized[$itemKey] = self::sanitizeInput($value, is_string($itemKey) ? $itemKey : $key);
            }

            return $sanitized;
        }

        if (is_string($data)) {
            // Remove null bytes
            $data = str_replace("\0", '', $data);

            if ($key !== null && in_array(strtolower($key), self::RAW_INPUT_KEYS, true)) {
                return $data;
            }

            // Convert special characters to HTML entities
            $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $data;
    }

    /**
     * Check for suspicious email patterns
     */
    private static function containsSuspiciousPatterns(string $email): bool
    {
        $suspicious = [
            '/\.\./',  // consecutive dots
            '/^\./',   // starts with dot
            '/\.$/',   // ends with dot
            '/@.*@/',  // multiple @ symbols
            '/\s/',    // whitespace
        ];

        foreach ($suspicious as $pattern) {
            if (preg_match($pattern, $email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for malicious content
     */
    private static function containsMaliciousContent(string $content): bool
    {
        $malicious = [
            '/<script/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/onload=/i',
            '/onerror=/i',
            '/<iframe/i',
            '/<object/i',
            '/<embed/i',
            '/eval\(/i',
            '/base64,/i',
            '/data:text/i',
        ];

        foreach ($malicious as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Password rules for the UI (delegated to PasswordPolicy).
     */
    public static function passwordRules(): array
    {
        return PasswordPolicy::describe();
    }
}
