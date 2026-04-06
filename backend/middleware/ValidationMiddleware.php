<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditLogger.php';

class ValidationMiddleware
{
    // Security settings
    private const MAX_STRING_LENGTH = 1000;
    private const MAX_EMAIL_LENGTH = 150;
    private const MAX_PASSWORD_LENGTH = 128;
    private const MIN_PASSWORD_LENGTH = 8;

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
     * Validate password strength and security
     */
    public static function validatePassword(string $password, bool $required = true): string
    {
        if ($required && empty($password)) {
            throw new InvalidArgumentException('Password is required.', 422);
        }

        if (empty($password)) {
            return '';
        }

        // Check length
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.', 422);
        }

        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Password is too long.', 422);
        }

        // Get password requirements from settings
        $requirements = self::getPasswordRequirements();

        // Check for uppercase letters
        if ($requirements['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one uppercase letter.', 422);
        }

        // Check for lowercase letters
        if ($requirements['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one lowercase letter.', 422);
        }

        // Check for numbers
        if ($requirements['require_numbers'] && !preg_match('/[0-9]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one number.', 422);
        }

        // Check for special characters
        if ($requirements['require_special_chars'] && !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
            throw new InvalidArgumentException('Password must contain at least one special character.', 422);
        }

        // Check for common weak passwords
        if (self::isCommonPassword($password)) {
            throw new InvalidArgumentException('Password is too common. Please choose a stronger password.', 422);
        }

        return $password;
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
        self::validateRequired($data, ['email', 'password']);

        $validated = [
            'email' => self::validateEmail($data['email']),
            'password' => self::validatePassword($data['password'])
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
     * Sanitize input data to prevent XSS
     */
    public static function sanitizeInput($data)
    {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }

        if (is_string($data)) {
            // Remove null bytes
            $data = str_replace("\0", '', $data);
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
     * Check if password is in common password list
     */
    private static function isCommonPassword(string $password): bool
    {
        $commonPasswords = [
            'password', '123456', '123456789', 'qwerty', 'abc123', 'password123',
            'admin', 'letmein', 'welcome', 'monkey', '1234567890', 'password1',
            'qwerty123', 'admin123', 'root', 'user', 'guest'
        ];

        return in_array(strtolower($password), $commonPasswords);
    }

    /**
     * Get password requirements from settings
     */
    private static function getPasswordRequirements(): array
    {
        try {
            $connection = Database::connection();
            $result = $connection->query("
                SELECT setting_key, setting_value
                FROM security_settings
                WHERE setting_key LIKE 'password_require_%'
            ");

            $requirements = [
                'require_uppercase' => true,
                'require_lowercase' => true,
                'require_numbers' => true,
                'require_special_chars' => false,
            ];

            while ($row = $result->fetch_assoc()) {
                $key = str_replace('password_require_', '', $row['setting_key']);
                if (array_key_exists($key, $requirements)) {
                    $requirements[$key] = in_array(strtolower($row['setting_value']), ['true', '1', 'yes', 'on']);
                }
            }

            return $requirements;
        } catch (Exception $e) {
            // Return defaults on error
            return [
                'require_uppercase' => true,
                'require_lowercase' => true,
                'require_numbers' => true,
                'require_special_chars' => false,
            ];
        }
    }
}
