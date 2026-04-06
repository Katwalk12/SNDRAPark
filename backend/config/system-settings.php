<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!function_exists('system_settings_defaults')) {
    function system_settings_defaults(): array
    {
        return [
            'system_name' => 'SNDRA Park',
            'contact_number' => '+63 917 555 0142',
            'gmail_address' => 'sndraparkemulator@gmail.com',
            'parking_base_rate' => 20.0,
            'extra_hourly_rate' => 10.0
        ];
    }
}

if (!function_exists('system_settings_normalize')) {
    function system_settings_normalize(array $settings): array
    {
        $defaults = system_settings_defaults();

        return [
            'system_name' => trim((string) ($settings['system_name'] ?? $defaults['system_name'])),
            'contact_number' => trim((string) ($settings['contact_number'] ?? $defaults['contact_number'])),
            'gmail_address' => trim((string) ($settings['gmail_address'] ?? $defaults['gmail_address'])),
            'parking_base_rate' => round((float) ($settings['parking_base_rate'] ?? $defaults['parking_base_rate']), 2),
            'extra_hourly_rate' => round((float) ($settings['extra_hourly_rate'] ?? $defaults['extra_hourly_rate']), 2)
        ];
    }
}

if (!function_exists('system_settings_fetch')) {
    function system_settings_fetch(?mysqli $connection = null): array
    {
        $connection = $connection instanceof mysqli ? $connection : booth_db();
        $settings = system_settings_defaults();
        $result = $connection->query("
            SELECT setting_key, setting_value
            FROM system_settings
        ");

        while ($row = $result->fetch_assoc()) {
            $settings[(string) $row['setting_key']] = $row['setting_value'];
        }

        return system_settings_normalize($settings);
    }
}

if (!function_exists('system_settings_base_rate')) {
    function system_settings_base_rate(?mysqli $connection = null): float
    {
        $settings = system_settings_fetch($connection);
        return (float) ($settings['parking_base_rate'] ?? 20.0);
    }
}

if (!function_exists('system_settings_extra_hourly_rate')) {
    function system_settings_extra_hourly_rate(?mysqli $connection = null): float
    {
        $settings = system_settings_fetch($connection);
        return (float) ($settings['extra_hourly_rate'] ?? 10.0);
    }
}
