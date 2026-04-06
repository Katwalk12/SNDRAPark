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

    $settings = [
        'system_name' => admin_clean_text(admin_input('system_name')),
        'contact_number' => admin_clean_text(admin_input('contact_number')),
        'gmail_address' => admin_clean_text(admin_input('gmail_address')),
        'parking_base_rate' => (string) admin_float(admin_input('parking_base_rate')),
        'extra_hourly_rate' => (string) admin_float(admin_input('extra_hourly_rate'))
    ];

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
