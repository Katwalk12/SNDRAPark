<?php

declare(strict_types=1);

/**
 * Scheduled maintenance sweep.
 *
 * Expiring a no-show reservation releases its slot and costs the driver a
 * warning. That work used to happen only inside request handlers, throttled by
 * a file in storage/cache -- so on a quiet night nothing expired, slots stayed
 * held, and the penalties landed at whatever hour somebody next signed in.
 *
 * Run this from Task Scheduler (or cron) every few minutes:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\sndraPark\backend\cli\run-maintenance.php
 *
 * tools/register-maintenance-task.bat registers exactly that for you.
 *
 * Flags:
 *   --quiet   only print when something changed or failed
 *   --json    print one JSON line instead of prose (for log shipping)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../parking/common.php';
require_once __DIR__ . '/../common/reservation-notifier.php';

$options = getopt('', ['quiet', 'json']);
$quiet = array_key_exists('quiet', $options);
$asJson = array_key_exists('json', $options);
$startedAt = microtime(true);

$report = [
    'started_at' => date('c'),
    'expired' => 0,
    'reminders_sent' => 0,
    'slots_synced' => false,
    'errors' => []
];

try {
    $connection = booth_db();

    // Remind first: a driver about to lose a slot deserves the warning before
    // the sweep in the next step takes it away.
    try {
        $report['reminders_sent'] = reservation_notifier_send_due_reminders($connection);
    } catch (Throwable $reminderException) {
        $report['errors'][] = 'reminders: ' . $reminderException->getMessage();
    }

    // force = true: the CLI is the authoritative sweep, so it ignores the
    // request-time throttle file.
    $expired = reservation_security_expire_due_reservations($connection, null, true);
    $report['expired'] = (int) ($expired['expired_count'] ?? 0);

    parking_sync_slot_statuses($connection, true);
    $report['slots_synced'] = true;
} catch (Throwable $exception) {
    $report['errors'][] = $exception->getMessage();
}

$report['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
$hasWork = $report['expired'] > 0 || $report['reminders_sent'] > 0;
$failed = $report['errors'] !== [];

if ($asJson) {
    echo json_encode($report, JSON_UNESCAPED_SLASHES), PHP_EOL;
} elseif ($failed || $hasWork || !$quiet) {
    printf(
        "[%s] expired=%d reminders=%d slots_synced=%s %dms%s%s",
        date('Y-m-d H:i:s'),
        $report['expired'],
        $report['reminders_sent'],
        $report['slots_synced'] ? 'yes' : 'no',
        $report['duration_ms'],
        $failed ? ' ERRORS: ' . implode('; ', $report['errors']) : '',
        PHP_EOL
    );
}

exit($failed ? 1 : 0);
