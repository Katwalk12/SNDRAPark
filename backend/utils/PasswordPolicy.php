<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Central password policy for SNDRA Park.
 *
 * Every place that accepts a password (registration, password change and the
 * forgot-password reset flow) runs through this class so the rules stay identical:
 *
 *  - at least 8 characters
 *  - uppercase and lowercase letters
 *  - at least one number
 *  - at least one special character
 *  - no easily guessed content (name, email, birth date, common passwords)
 *  - reminders to change the password periodically (password_max_age_days)
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    /** Personal words shorter than this are ignored to avoid false rejections. */
    private const MIN_PERSONAL_TOKEN_LENGTH = 3;

    private static ?array $settingsCache = null;

    /**
     * Effective policy settings, sourced from security_settings with safe defaults.
     */
    public static function settings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $settings = [
            'min_length' => self::MIN_LENGTH,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_special_chars' => true,
            'block_personal_info' => true,
            'max_age_days' => 90,
            'expiry_warning_days' => 14,
        ];

        $keyMap = [
            'password_min_length' => 'min_length',
            'password_require_uppercase' => 'require_uppercase',
            'password_require_lowercase' => 'require_lowercase',
            'password_require_numbers' => 'require_numbers',
            'password_require_special_chars' => 'require_special_chars',
            'password_block_personal_info' => 'block_personal_info',
            'password_max_age_days' => 'max_age_days',
            'password_expiry_warning_days' => 'expiry_warning_days',
        ];

        try {
            $connection = Database::connection();
            $result = $connection->query("
                SELECT setting_key, setting_value
                FROM security_settings
                WHERE setting_key LIKE 'password_%'
            ");

            while ($result && ($row = $result->fetch_assoc())) {
                $target = $keyMap[$row['setting_key']] ?? null;

                if ($target === null) {
                    continue;
                }

                $settings[$target] = is_bool($settings[$target])
                    ? in_array(strtolower(trim((string) $row['setting_value'])), ['true', '1', 'yes', 'on'], true)
                    : (int) $row['setting_value'];
            }
        } catch (Throwable $exception) {
            // Keep the defaults when the settings table is unavailable.
        }

        $settings['min_length'] = max(self::MIN_LENGTH, (int) $settings['min_length']);
        self::$settingsCache = $settings;

        return $settings;
    }

    /**
     * Human readable rules, used by the UI and by error messages.
     */
    public static function describe(): array
    {
        $settings = self::settings();
        $rules = ['Be at least ' . $settings['min_length'] . ' characters long.'];

        if ($settings['require_uppercase'] && $settings['require_lowercase']) {
            $rules[] = 'Include uppercase and lowercase letters.';
        } elseif ($settings['require_uppercase']) {
            $rules[] = 'Include at least one uppercase letter.';
        } elseif ($settings['require_lowercase']) {
            $rules[] = 'Include at least one lowercase letter.';
        }

        if ($settings['require_numbers']) {
            $rules[] = 'Include at least one number.';
        }

        if ($settings['require_special_chars']) {
            $rules[] = 'Include at least one special character.';
        }

        if ($settings['block_personal_info']) {
            $rules[] = 'Not contain easily guessed information such as your name, email or birth date.';
        }

        return $rules;
    }

    /**
     * Collect every rule the password breaks.
     *
     * @param array $context Optional user details: first_name, last_name, full_name, email, birth_date.
     * @return string[] Empty when the password satisfies the policy.
     */
    public static function check(string $password, array $context = []): array
    {
        $settings = self::settings();
        $errors = [];

        if (strlen($password) < $settings['min_length']) {
            $errors[] = 'Password must be at least ' . $settings['min_length'] . ' characters long.';
        }

        if (strlen($password) > self::MAX_LENGTH) {
            $errors[] = 'Password must be ' . self::MAX_LENGTH . ' characters or fewer.';
        }

        if ($settings['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if ($settings['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if ($settings['require_numbers'] && !preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        if ($settings['require_special_chars'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character (for example ! @ # $ %).';
        }

        if (preg_match('/\s/', $password)) {
            $errors[] = 'Password must not contain spaces.';
        }

        if (self::isCommonPassword($password)) {
            $errors[] = 'Password is too common and easy to guess. Please choose another one.';
        }

        if ($settings['block_personal_info'] && self::containsPersonalInfo($password, $context)) {
            $errors[] = 'Password must not contain your name, email or birth date.';
        }

        return $errors;
    }

    /**
     * Validate a password, throwing the first broken rule as a 422 error.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $password, array $context = [], bool $required = true): string
    {
        if ($password === '') {
            if ($required) {
                throw new InvalidArgumentException('Password is required.', 422);
            }

            return '';
        }

        $errors = self::check($password, $context);

        if (!empty($errors)) {
            throw new InvalidArgumentException($errors[0], 422);
        }

        return $password;
    }

    /**
     * Strength score from 0 to 4, mirrored by the front-end meter.
     */
    public static function score(string $password, array $context = []): int
    {
        if ($password === '') {
            return 0;
        }

        $score = 0;

        if (preg_match('/[A-Z]/', $password)) {
            $score++;
        }

        if (preg_match('/[a-z]/', $password)) {
            $score++;
        }

        if (preg_match('/\d/', $password)) {
            $score++;
        }

        if (preg_match('/[^A-Za-z0-9]/', $password) && strlen($password) >= self::settings()['min_length']) {
            $score++;
        }

        if (self::isCommonPassword($password) || self::containsPersonalInfo($password, $context)) {
            $score = min($score, 1);
        }

        return $score;
    }

    /**
     * Password ageing status for the "change your password periodically" rule.
     *
     * @return array{enabled:bool,age_days:?int,expires_in_days:?int,expired:bool,warn:bool,message:?string}
     */
    public static function expiryStatus(?string $passwordChangedAt): array
    {
        $settings = self::settings();
        $maxAgeDays = (int) $settings['max_age_days'];
        $warningDays = (int) $settings['expiry_warning_days'];

        $status = [
            'enabled' => $maxAgeDays > 0,
            'age_days' => null,
            'expires_in_days' => null,
            'expired' => false,
            'warn' => false,
            'message' => null,
        ];

        if ($maxAgeDays <= 0) {
            return $status;
        }

        $changedAt = $passwordChangedAt ? strtotime($passwordChangedAt) : false;

        if ($changedAt === false) {
            return $status;
        }

        // Clamped because the database clock may run slightly ahead of PHP's.
        $ageDays = max(0, (int) floor((time() - $changedAt) / 86400));
        $remainingDays = $maxAgeDays - $ageDays;

        $status['age_days'] = $ageDays;
        $status['expires_in_days'] = $remainingDays;

        if ($remainingDays <= 0) {
            $status['expired'] = true;
            $status['warn'] = true;
            $status['message'] = 'Your password is more than ' . $maxAgeDays . ' days old. Please change it now to keep your account secure.';

            return $status;
        }

        if ($remainingDays <= $warningDays) {
            $status['warn'] = true;
            $status['message'] = 'Your password expires in ' . $remainingDays . ' day' . ($remainingDays === 1 ? '' : 's') . '. Please change it soon.';
        }

        return $status;
    }

    /**
     * Build the personal-information context from a user row.
     */
    public static function contextFromUser(?array $user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'full_name' => $user['full_name'] ?? null,
            'email' => $user['email'] ?? null,
            'birth_date' => $user['birth_date'] ?? null,
        ];
    }

    /**
     * Does the password embed the user's name, email or birth date?
     */
    public static function containsPersonalInfo(string $password, array $context): bool
    {
        $haystack = strtolower($password);

        foreach (self::personalTokens($context) as $token) {
            if ($token !== '' && strpos($haystack, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase fragments that must not appear inside a password.
     *
     * @return string[]
     */
    public static function personalTokens(array $context): array
    {
        $tokens = [];

        foreach (['first_name', 'last_name', 'full_name'] as $key) {
            foreach (preg_split('/[^a-z]+/', strtolower((string) ($context[$key] ?? ''))) ?: [] as $word) {
                if (strlen($word) >= self::MIN_PERSONAL_TOKEN_LENGTH) {
                    $tokens[] = $word;
                }
            }
        }

        $email = strtolower(trim((string) ($context['email'] ?? '')));

        if ($email !== '') {
            $localPart = explode('@', $email)[0];

            if (strlen($localPart) >= self::MIN_PERSONAL_TOKEN_LENGTH) {
                $tokens[] = $localPart;
            }

            foreach (preg_split('/[^a-z0-9]+/', $localPart) ?: [] as $word) {
                if (strlen($word) >= self::MIN_PERSONAL_TOKEN_LENGTH) {
                    $tokens[] = $word;
                }
            }
        }

        foreach (self::birthDateTokens((string) ($context['birth_date'] ?? '')) as $token) {
            $tokens[] = $token;
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * Common ways a birth date shows up inside a password.
     *
     * @return string[]
     */
    private static function birthDateTokens(string $birthDate): array
    {
        $birthDate = trim($birthDate);

        if ($birthDate === '') {
            return [];
        }

        $timestamp = strtotime($birthDate);

        if ($timestamp === false) {
            return [];
        }

        $year = date('Y', $timestamp);
        $shortYear = date('y', $timestamp);
        $month = date('m', $timestamp);
        $day = date('d', $timestamp);

        return [
            $year,
            $month . $day,
            $day . $month,
            $month . $day . $year,
            $day . $month . $year,
            $year . $month . $day,
            $month . $day . $shortYear,
            $day . $month . $shortYear,
            strtolower(date('F', $timestamp)),
        ];
    }

    /**
     * Weak passwords that attackers try first.
     */
    public static function isCommonPassword(string $password): bool
    {
        $normalized = strtolower($password);

        $exactMatches = [
            'password', 'password1', 'password123', 'passw0rd', '12345678', '123456789', '1234567890',
            'qwerty', 'qwerty123', 'abc123', 'admin', 'admin123', 'root', 'user', 'guest',
            'welcome', 'welcome1', 'letmein', 'monkey', 'iloveyou', 'sunshine', 'changeme',
            'p@ssw0rd', 'p@ssword', 'secret', 'test1234', 'default',
        ];

        if (in_array($normalized, $exactMatches, true)) {
            return true;
        }

        // Longer weak words are also rejected as substrings.
        $weakFragments = [
            'password', 'passw0rd', 'p@ssw0rd', 'qwerty', 'letmein', 'welcome', 'iloveyou',
            'changeme', 'sndrapark', 'sndra', 'parking', '123456', '12345678', 'abcdef',
        ];

        foreach ($weakFragments as $fragment) {
            if (strpos($normalized, $fragment) !== false) {
                return true;
            }
        }

        // A single repeated character is trivially guessable (e.g. "aaaaaaaa").
        if (preg_match('/^(.)\1+$/', $password)) {
            return true;
        }

        return false;
    }

    /**
     * Reset the cached settings (used after an admin updates the security settings).
     */
    public static function flushCache(): void
    {
        self::$settingsCache = null;
    }
}
