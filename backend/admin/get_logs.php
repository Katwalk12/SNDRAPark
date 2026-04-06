<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');

try {
    $connection = admin_db();
    $logFeed = admin_audit_fetch_grouped($connection, 250);

    admin_success('Admin audit logs loaded successfully.', [
        'totalEntries' => $logFeed['totalEntries'] ?? 0,
        'groups' => $logFeed['groups'] ?? [],
        'live_at' => date('c')
    ]);
} catch (Throwable $exception) {
    admin_log('get-logs-failed', [
        'error' => $exception->getMessage()
    ]);
    admin_error('Failed to load logs.', 500, [
        'details' => $exception->getMessage()
    ]);
}
