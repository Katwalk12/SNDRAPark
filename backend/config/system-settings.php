<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!function_exists('system_settings_defaults')) {
    function system_settings_defaults(): array
    {
        return [
            'system_name' => 'SNDRA Park',
            'contact_number' => '+63 917 555 0142',
            'gmail_address' => 'sndraparksupport@gmail.com',

            // Pricing. The base rate covers the first `base_hours` hours; every
            // hour past that costs `extra_hourly_rate`.
            'parking_base_rate' => 20.0,
            'extra_hourly_rate' => 10.0,
            'base_included_hours' => 3.0,

            // Vehicle-class multipliers applied to the whole computed fee, so a
            // motorcycle no longer pays what an SUV pays.
            'rate_multiplier_motorcycle' => 0.5,
            'rate_multiplier_car' => 1.0,
            'rate_multiplier_suv' => 1.5,
            'rate_multiplier_truck' => 2.0,

            // Night band. A stay that starts inside the band is surcharged by
            // this percentage; 0 turns the band off.
            'night_rate_start' => '22:00',
            'night_rate_end' => '06:00',
            'night_rate_surcharge_percent' => 0.0,

            // RA 9994 (senior citizens) and RA 10754 (persons with disability)
            // both mandate a 20% discount on services.
            'statutory_discount_percent' => 20.0,

            // Operating window and booking rules.
            'parking_opening_time' => '08:00',
            'parking_closing_time' => '22:00',
            'parking_same_day_cutoff' => '21:00',

            // No-show policy, measured from the reserved arrival time.
            'reservation_grace_minutes' => 30,
            'reservation_reminder_minutes' => 10,
            'reservation_warning_allowance' => 3,
            'reservation_warning_window_days' => 30,

            // Outgoing email for confirmations, reminders and receipts.
            'notify_email_enabled' => 1,

            // Emailed second factor for admin sign-in. Off by default so a
            // fresh install is not locked out before SMTP is configured.
            'admin_2fa_enabled' => 0
        ];
    }
}

if (!function_exists('system_settings_clamp_time')) {
    function system_settings_clamp_time($value, string $fallback): string
    {
        $candidate = trim((string) ($value ?? ''));

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $candidate)) {
            return $candidate;
        }

        // Accept the HH:MM:SS that MySQL TIME columns hand back.
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d):[0-5]\d$/', $candidate)) {
            return substr($candidate, 0, 5);
        }

        return $fallback;
    }
}

if (!function_exists('system_settings_normalize')) {
    function system_settings_normalize(array $settings): array
    {
        $defaults = system_settings_defaults();

        $text = static function (string $key) use ($settings, $defaults): string {
            $value = trim((string) ($settings[$key] ?? ''));
            return $value !== '' ? $value : (string) $defaults[$key];
        };

        $money = static function (string $key, float $min, float $max) use ($settings, $defaults): float {
            $value = array_key_exists($key, $settings) && $settings[$key] !== ''
                ? (float) $settings[$key]
                : (float) $defaults[$key];
            return round(max($min, min($max, $value)), 2);
        };

        $count = static function (string $key, int $min, int $max) use ($settings, $defaults): int {
            $value = array_key_exists($key, $settings) && $settings[$key] !== ''
                ? (int) $settings[$key]
                : (int) $defaults[$key];
            return max($min, min($max, $value));
        };

        return [
            'system_name' => $text('system_name'),
            'contact_number' => $text('contact_number'),
            'gmail_address' => $text('gmail_address'),

            'parking_base_rate' => $money('parking_base_rate', 0.0, 100000.0),
            'extra_hourly_rate' => $money('extra_hourly_rate', 0.0, 100000.0),
            'base_included_hours' => $money('base_included_hours', 0.0, 24.0),

            'rate_multiplier_motorcycle' => $money('rate_multiplier_motorcycle', 0.0, 20.0),
            'rate_multiplier_car' => $money('rate_multiplier_car', 0.0, 20.0),
            'rate_multiplier_suv' => $money('rate_multiplier_suv', 0.0, 20.0),
            'rate_multiplier_truck' => $money('rate_multiplier_truck', 0.0, 20.0),

            'night_rate_start' => system_settings_clamp_time($settings['night_rate_start'] ?? null, (string) $defaults['night_rate_start']),
            'night_rate_end' => system_settings_clamp_time($settings['night_rate_end'] ?? null, (string) $defaults['night_rate_end']),
            'night_rate_surcharge_percent' => $money('night_rate_surcharge_percent', 0.0, 300.0),

            'statutory_discount_percent' => $money('statutory_discount_percent', 0.0, 100.0),

            'parking_opening_time' => system_settings_clamp_time($settings['parking_opening_time'] ?? null, (string) $defaults['parking_opening_time']),
            'parking_closing_time' => system_settings_clamp_time($settings['parking_closing_time'] ?? null, (string) $defaults['parking_closing_time']),
            'parking_same_day_cutoff' => system_settings_clamp_time($settings['parking_same_day_cutoff'] ?? null, (string) $defaults['parking_same_day_cutoff']),

            'reservation_grace_minutes' => $count('reservation_grace_minutes', 5, 720),
            'reservation_reminder_minutes' => $count('reservation_reminder_minutes', 0, 240),
            'reservation_warning_allowance' => $count('reservation_warning_allowance', 1, 20),
            'reservation_warning_window_days' => $count('reservation_warning_window_days', 1, 365),

            'notify_email_enabled' => $count('notify_email_enabled', 0, 1),
            'admin_2fa_enabled' => $count('admin_2fa_enabled', 0, 1)
        ];
    }
}

if (!function_exists('system_settings_forget')) {
    /** Drop the per-request cache after a settings write. */
    function system_settings_forget(): void
    {
        $GLOBALS['__system_settings_cache'] = null;
    }
}

if (!function_exists('system_settings_fetch')) {
    function system_settings_fetch(?mysqli $connection = null): array
    {
        // Several of these are read many times in one request (the fee
        // calculator alone wanted two round trips), so the row set is cached
        // for the life of the request and dropped by system_settings_forget().
        if (!empty($GLOBALS['__system_settings_cache'])) {
            return $GLOBALS['__system_settings_cache'];
        }

        $connection = $connection instanceof mysqli ? $connection : booth_db();
        $settings = system_settings_defaults();
        $result = $connection->query("
            SELECT setting_key, setting_value
            FROM system_settings
        ");

        while ($row = $result->fetch_assoc()) {
            $settings[(string) $row['setting_key']] = $row['setting_value'];
        }

        $normalized = system_settings_normalize($settings);
        $GLOBALS['__system_settings_cache'] = $normalized;

        return $normalized;
    }
}

if (!function_exists('system_settings_value')) {
    function system_settings_value(string $key, ?mysqli $connection = null)
    {
        $settings = system_settings_fetch($connection);
        $defaults = system_settings_defaults();

        return $settings[$key] ?? $defaults[$key] ?? null;
    }
}

if (!function_exists('system_settings_base_rate')) {
    function system_settings_base_rate(?mysqli $connection = null): float
    {
        return (float) system_settings_value('parking_base_rate', $connection);
    }
}

if (!function_exists('system_settings_extra_hourly_rate')) {
    function system_settings_extra_hourly_rate(?mysqli $connection = null): float
    {
        return (float) system_settings_value('extra_hourly_rate', $connection);
    }
}

if (!function_exists('system_settings_grace_minutes')) {
    function system_settings_grace_minutes(?mysqli $connection = null): int
    {
        return (int) system_settings_value('reservation_grace_minutes', $connection);
    }
}

if (!function_exists('system_settings_vehicle_multiplier')) {
    /** Rate multiplier for a vehicle class, defaulting to the car rate. */
    function system_settings_vehicle_multiplier(?string $vehicleType, ?mysqli $connection = null): float
    {
        $normalized = strtolower(trim((string) $vehicleType));
        $map = [
            'motorcycle' => 'rate_multiplier_motorcycle',
            'motorbike' => 'rate_multiplier_motorcycle',
            'scooter' => 'rate_multiplier_motorcycle',
            'car' => 'rate_multiplier_car',
            'sedan' => 'rate_multiplier_car',
            'hatchback' => 'rate_multiplier_car',
            'suv' => 'rate_multiplier_suv',
            'van' => 'rate_multiplier_suv',
            'pickup' => 'rate_multiplier_suv',
            'truck' => 'rate_multiplier_truck'
        ];

        $key = $map[$normalized] ?? 'rate_multiplier_car';

        return (float) system_settings_value($key, $connection);
    }
}
