<?php

declare(strict_types=1);

require_once __DIR__ . '/system-settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    booth_error('Method not allowed.', 405);
}

try {
    booth_success('System settings loaded successfully.', [
        'settings' => system_settings_fetch()
    ]);
} catch (Throwable $exception) {
    booth_log('get-system-settings-failed', [
        'error' => $exception->getMessage()
    ]);

    booth_error('Failed to load system settings.', 500, [
        'details' => $exception->getMessage()
    ]);
}
