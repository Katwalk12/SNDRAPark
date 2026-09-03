<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();

    if (admin_method() === 'GET') {
        admin_success('System settings loaded successfully.', [
            'settings' => admin_get_settings_map($connection)
        ]);
    }

    admin_require_method('POST');

    // SECURITY: Validate CSRF token for POST requests
    admin_require_csrf();

    // Only keys the settings form owns are writable, and every value is run
    // through system_settings_normalize() so a bad number cannot land in the
    // table and take pricing or the no-show policy with it.
    $submitted = [];

    foreach (array_keys(system_settings_defaults()) as $key) {
        $value = admin_input($key);

        if ($value !== null) {
            $submitted[$key] = is_string($value) ? trim($value) : $value;
        }
    }

    $current = system_settings_fetch($connection);
    $settings = [];

    foreach (system_settings_normalize(array_merge($current, $submitted)) as $key => $value) {
        $settings[$key] = (string) $value;
    }

    $statement = $connection->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    foreach ($settings as $key => $value) {
        $statement->bind_param('ss', $key, $value);
        $statement->execute();
    }

    admin_audit_log($connection, $admin, 'ADMIN_SETTINGS_UPDATED', 'Admin updated the system settings.', [
        'target_type' => 'system_settings',
        'target_id' => 'global',
        'status' => 'success',
        'metadata' => $settings
    ]);

    system_settings_forget();

    admin_success('System settings saved successfully.', [
        'settings' => admin_get_settings_map($connection)
    ]);
} catch (Throwable $exception) {
    admin_log('save-settings-failed', [
        'method' => admin_method(),
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to save settings.', 500, [
        'details' => $exception->getMessage()
    ]);
}
