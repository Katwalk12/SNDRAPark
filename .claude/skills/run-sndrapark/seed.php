<?php
/**
 * Idempotent test-account seeder for the run-sndrapark skill.
 *
 * Creates three clearly-namespaced accounts used by driver.mjs. Safe to re-run:
 * every write is keyed on the account's identifier, so it never touches or
 * overwrites the real accounts that already live in this database.
 *
 *   php .claude/skills/run-sndrapark/seed.php
 */
declare(strict_types=1);

$env = [];
foreach (explode("\n", (string) @file_get_contents(__DIR__ . '/../../../.env')) as $line) {
    if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*)$/', $line, $m)) {
        $env[$m[1]] = trim($m[2]);
    }
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$name = $env['DB_NAME'] ?? 'sndrapark_db';
$db = new PDO(
    "mysql:host={$host};port=" . ($env['DB_PORT'] ?? '3306') . ";dbname={$name};charset=utf8mb4",
    $env['DB_USER'] ?? 'root',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

const USER_EMAIL  = 'smoke.test@sndrapark.local';
const USER_PASS   = 'Qx7#vRm2!pLz';
const ADMIN_EMAIL = 'smoke.admin@sndrapark.local';
const ADMIN_PASS  = 'Qx7#vRm2!pLz';
const BOOTH_PIN   = '2468';

// --- member -------------------------------------------------------------
// Registration normally goes through the API (which also creates the vehicle
// row), so only repair the password here if the account already exists.
$st = $db->prepare('SELECT id FROM users WHERE email = ?');
$st->execute([USER_EMAIL]);
if ($id = $st->fetchColumn()) {
    $db->prepare('UPDATE users SET password_hash = ?, account_status = "active",
                  failed_login_attempts = 0, login_locked_until = NULL WHERE id = ?')
       ->execute([password_hash(USER_PASS, PASSWORD_DEFAULT), $id]);
    echo "member  : ok (id {$id})\n";
} else {
    echo "member  : MISSING — register it via the API:\n";
    echo "          curl -s -X POST \"\$BASE/backend/api/v1/register\" -H 'Content-Type: application/json' \\n";
    echo "            -d '{\"firstName\":\"Smoke\",\"lastName\":\"Test\",\"email\":\"" . USER_EMAIL . "\",\"password\":\"" . USER_PASS . "\",\"birthDate\":\"1998-01-15\",\"vehicleType\":\"Car\",\"plateNumber\":\"SMK-0001\",\"vehicleBrand\":\"Toyota\",\"vehicleModel\":\"Vios\",\"vehicleColor\":\"Blue\"}'\n";
}

// --- admin --------------------------------------------------------------
// staff_accounts.password_hash is char(64); admin_staff_password_verify() treats
// a 64-char hex value as sha256, so match that rather than bcrypt (60 chars).
$st = $db->prepare('SELECT id FROM staff_accounts WHERE email = ?');
$st->execute([ADMIN_EMAIL]);
$hash = hash('sha256', ADMIN_PASS);
if ($id = $st->fetchColumn()) {
    $db->prepare('UPDATE staff_accounts SET password_hash = ?, is_active = 1, role = "admin" WHERE id = ?')
       ->execute([$hash, $id]);
    echo "admin   : ok (id {$id})\n";
} else {
    $db->prepare('INSERT INTO staff_accounts (full_name, username, email, password_hash, role, is_active, created_at, updated_at)
                  VALUES ("Smoke Admin", "smoke.admin", ?, ?, "admin", 1, NOW(), NOW())')
       ->execute([ADMIN_EMAIL, $hash]);
    echo "admin   : created (id {$db->lastInsertId()})\n";
}

// --- booth teller -------------------------------------------------------
// Booth login is PIN-only and scans every active teller row with password_verify,
// so the PIN must be bcrypt and must not collide with an existing teller's PIN.
$st = $db->prepare('SELECT id FROM booth_teller_accounts WHERE teller_name = ?');
$st->execute(['Smoke Teller']);
$pin = password_hash(BOOTH_PIN, PASSWORD_DEFAULT);
if ($id = $st->fetchColumn()) {
    $db->prepare('UPDATE booth_teller_accounts SET pin_code = ?, is_active = 1 WHERE id = ?')
       ->execute([$pin, $id]);
    echo "booth   : ok (id {$id})\n";
} else {
    $db->prepare('INSERT INTO booth_teller_accounts (teller_name, teller_details, pin_code, is_active, created_at, updated_at)
                  VALUES ("Smoke Teller", "run-sndrapark skill test account", ?, 1, NOW(), NOW())')
       ->execute([$pin]);
    echo "booth   : created (id {$db->lastInsertId()})\n";
}

echo "\nmember " . USER_EMAIL . " / " . USER_PASS . "\n";
echo "admin  " . ADMIN_EMAIL . " / " . ADMIN_PASS . "\n";
echo "booth  PIN " . BOOTH_PIN . "\n";

// --- cleanup ------------------------------------------------------------
// `php seed.php --clean` removes only the data the smoke run creates, so the
// developer's own reservations and slot states are never touched.
if (in_array('--clean', $argv ?? [], true)) {
    echo "\n-- cleaning smoke-test data --\n";

    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([USER_EMAIL]);
    $uid = (int) $st->fetchColumn();

    if (!$uid) {
        echo "no smoke user; nothing to clean\n";
        return;
    }

    // Reservations created through the UI store the slot as text with a NULL
    // parking_slot_id, so free the slot by (floor_name, slot_code) instead.
    $st = $db->prepare('SELECT id, parking_floor, parking_slot FROM reservations WHERE user_id = ?');
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $freeSlot = $db->prepare(
        'UPDATE parking_slots SET status = "Available", manual_status = "Auto"
         WHERE floor_name = ? AND slot_code = ? AND status <> "Inactive"'
    );
    $delTxn = $db->prepare('DELETE FROM parking_transactions WHERE reservation_id = ?');
    // payments has an FK onto reservations, so it has to go first.
    $delPay = $db->prepare('DELETE FROM payments WHERE reservation_id = ?');
    // So does notifications, now that confirmations and reminders reference
    // the reservation they are about.
    $delNotif = $db->prepare('DELETE FROM notifications WHERE reservation_id = ?');

    foreach ($rows as $r) {
        $delNotif->execute([$r['id']]);
        $delPay->execute([$r['id']]);
        $delTxn->execute([$r['id']]);
        $freeSlot->execute([$r['parking_floor'], $r['parking_slot']]);
        echo "freed {$r['parking_floor']}/{$r['parking_slot']} (reservation {$r['id']})\n";
    }

    $db->prepare('DELETE FROM reservations WHERE user_id = ?')->execute([$uid]);
    $db->prepare('DELETE FROM rate_limit_attempts WHERE identifier LIKE ?')->execute(['%' . USER_EMAIL]);
    $db->prepare('DELETE FROM rate_limit_attempts WHERE identifier LIKE ?')->execute(['%' . ADMIN_EMAIL]);
    echo "reservations + rate-limit rows removed for user {$uid}\n";
}
