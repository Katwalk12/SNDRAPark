<?php

declare(strict_types=1);

/**
 * SNDRA Park test suite.
 *
 * Run it:  C:\xampp\php\php.exe tests\run.php
 *
 * There is no Composer in this project, so this is a plain runner rather than
 * PHPUnit -- the point is that the logic most likely to be wrong, and hardest
 * to click through in a browser, has an assertion on it: what a stay costs,
 * when a reservation dies, and which origins the API answers.
 *
 * The pricing and policy code reads its numbers from system_settings, which is
 * cached per request in a global. Priming that global lets these tests run
 * against fixed rates with no database at all.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../backend/config/app.php';
require_once __DIR__ . '/../backend/config/system-settings.php';
require_once __DIR__ . '/../backend/parking-booth/common.php';
require_once __DIR__ . '/../backend/common/reservation-security.php';
require_once __DIR__ . '/../backend/utils/CorsHelper.php';
require_once __DIR__ . '/../backend/admin/common.php';
require_once __DIR__ . '/../backend/utils/PasswordPolicy.php';
require_once __DIR__ . '/../backend/iot/common.php';

final class TestRunner
{
    private int $passed = 0;
    private array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n" . $name . "\n";
    }

    public function assertSame($expected, $actual, string $what): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  PASS  " . $what . "\n";
            return;
        }

        $this->failures[] = $this->group . ' / ' . $what
            . "\n         expected: " . var_export($expected, true)
            . "\n         actual:   " . var_export($actual, true);
        echo "  FAIL  " . $what . " (expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . ")\n";
    }

    public function assertTrue(bool $condition, string $what): void
    {
        $this->assertSame(true, $condition, $what);
    }

    public function summary(): int
    {
        $failed = count($this->failures);
        echo "\n" . str_repeat('-', 60) . "\n";
        printf("%d passed, %d failed\n", $this->passed, $failed);

        foreach ($this->failures as $failure) {
            echo "\n  " . $failure . "\n";
        }

        return $failed === 0 ? 0 : 1;
    }
}

/** Pin the settings the code under test will read. */
function with_settings(array $overrides): void
{
    $GLOBALS['__system_settings_cache'] = system_settings_normalize(
        array_merge(system_settings_defaults(), $overrides)
    );
}

/** A mysqli handle that is never connected: the cache above answers instead. */
function offline_connection(): mysqli
{
    return mysqli_init();
}

$test = new TestRunner();
$db = offline_connection();

// ---------------------------------------------------------------- pricing --
$test->group('booth_calculate_payment');

with_settings([
    'parking_base_rate' => 20,
    'extra_hourly_rate' => 10,
    'base_included_hours' => 3,
    'night_rate_surcharge_percent' => 0,
    'statutory_discount_percent' => 20,
    'rate_multiplier_car' => 1.0,
    'rate_multiplier_motorcycle' => 0.5,
    'rate_multiplier_suv' => 1.5
]);

$oneHour = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(20.0, $oneHour['total_payment'], 'one hour costs the base rate');
$test->assertSame(1.0, $oneHour['total_hours_stayed'], 'one hour is billed as one hour');

$threeHours = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 12:00:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(20.0, $threeHours['total_payment'], 'three hours are still inside the base');

$fiveHours = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 14:00:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(40.0, $fiveHours['total_payment'], 'five hours add two overtime hours');

$partial = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 12:01:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(30.0, $partial['total_payment'], 'a started hour is a whole hour');

$zero = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 09:00:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(20.0, $zero['total_payment'], 'a zero-length stay is billed one hour, never nothing');

$motorcycle = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 14:00:00', 20.0, ['vehicle_type' => 'Motorcycle']);
$test->assertSame(20.0, $motorcycle['total_payment'], 'a motorcycle pays half of what a car pays');

$suv = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 20.0, ['vehicle_type' => 'SUV']);
$test->assertSame(30.0, $suv['total_payment'], 'an SUV pays 1.5x');

$unknownClass = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 20.0, ['vehicle_type' => 'Hovercraft']);
$test->assertSame(20.0, $unknownClass['total_payment'], 'an unknown vehicle class falls back to the car rate');

$senior = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 20.0, [
    'vehicle_type' => 'Car',
    'discount_type' => 'Senior'
]);
$test->assertSame(16.0, $senior['total_payment'], 'a senior pays 20 percent less');
$test->assertSame(4.0, $senior['discount_amount'], 'the discount amount is recorded');
$test->assertSame('Senior', $senior['discount_type'], 'the discount type is recorded');

$pwd = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 14:00:00', 20.0, [
    'vehicle_type' => 'Car',
    'discount_type' => 'pwd'
]);
$test->assertSame(32.0, $pwd['total_payment'], 'PWD discount applies to overtime too');

$withId = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 20.0, [
    'vehicle_type' => 'Car',
    'discount_type' => 'Senior',
    'discount_id_number' => ' sc-2024-001234 '
]);
$test->assertSame('SC-2024-001234', $withId['discount_id_number'], 'the discount ID is normalised and kept with the price');

$noDiscountId = booth_calculate_payment($db, '2026-09-01 09:00:00', '2026-09-01 10:00:00', 20.0, [
    'vehicle_type' => 'Car',
    'discount_type' => 'None',
    'discount_id_number' => 'SC-2024-001234'
]);
$test->assertSame(null, $noDiscountId['discount_id_number'], 'an undiscounted stay keeps no discount ID');

// ------------------------------------------------------------- night band --
$test->group('night surcharge');

with_settings([
    'parking_base_rate' => 20,
    'extra_hourly_rate' => 10,
    'base_included_hours' => 3,
    'night_rate_start' => '22:00',
    'night_rate_end' => '06:00',
    'night_rate_surcharge_percent' => 50,
    'statutory_discount_percent' => 20
]);

$night = booth_calculate_payment($db, '2026-09-01 23:00:00', '2026-09-02 00:00:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(30.0, $night['total_payment'], 'a stay starting at 23:00 carries the 50 percent surcharge');

$day = booth_calculate_payment($db, '2026-09-01 12:00:00', '2026-09-01 13:00:00', 20.0, ['vehicle_type' => 'Car']);
$test->assertSame(20.0, $day['total_payment'], 'a midday stay carries no surcharge');

$test->assertTrue(booth_time_is_in_night_band('2026-09-01 23:30:00', '22:00', '06:00'), 'band wraps past midnight (23:30)');
$test->assertTrue(booth_time_is_in_night_band('2026-09-01 02:00:00', '22:00', '06:00'), 'band wraps past midnight (02:00)');
$test->assertSame(false, booth_time_is_in_night_band('2026-09-01 12:00:00', '22:00', '06:00'), 'noon is outside the night band');
$test->assertSame(false, booth_time_is_in_night_band('2026-09-01 12:00:00', '08:00', '08:00'), 'an empty band never matches');

// ------------------------------------------------------------ normalisers --
$test->group('input normalisers');

$test->assertSame('Senior', booth_normalize_discount_type('senior citizen'), 'senior citizen maps to Senior');
$test->assertSame('PWD', booth_normalize_discount_type('PWD'), 'PWD maps to PWD');
$test->assertSame('None', booth_normalize_discount_type('student'), 'an unrecognised discount is refused');
$test->assertSame('None', booth_normalize_discount_type(null), 'a missing discount is None');

$test->assertSame('SC-2024-001234', booth_normalize_discount_id('  sc-2024-001234  '), 'a discount ID is upper-cased and trimmed');
$test->assertSame('PWD 12 345', booth_normalize_discount_id("pwd  12	345"), 'runs of whitespace collapse to one space');
$test->assertSame(null, booth_normalize_discount_id('   '), 'a blank discount ID is null, not an empty string');
$test->assertTrue(booth_discount_id_is_valid(booth_normalize_discount_id('SC-2024-001234')), 'a card number is accepted');
$test->assertSame(false, booth_discount_id_is_valid(booth_normalize_discount_id('12')), 'too short to be a card number is refused');
$test->assertSame(false, booth_discount_id_is_valid(null), 'a missing discount ID is not valid');

$test->assertSame('GCash', booth_normalize_payment_method('gcash'), 'gcash maps to GCash');
$test->assertSame('Maya', booth_normalize_payment_method('paymaya'), 'paymaya maps to Maya');
$test->assertSame('Cash', booth_normalize_payment_method('bitcoin'), 'an unsupported tender falls back to Cash');

// --------------------------------------------------------------- settings --
$test->group('system_settings_normalize');

$clamped = system_settings_normalize(array_merge(system_settings_defaults(), [
    'reservation_grace_minutes' => 99999,
    'statutory_discount_percent' => -5,
    'parking_opening_time' => 'not a time',
    'reservation_warning_allowance' => 0
]));

$test->assertSame(720, $clamped['reservation_grace_minutes'], 'an absurd grace period is clamped to the maximum');
$test->assertSame(0.0, $clamped['statutory_discount_percent'], 'a negative discount is clamped to zero');
$test->assertSame('08:00', $clamped['parking_opening_time'], 'an invalid time falls back to the default');
$test->assertSame(1, $clamped['reservation_warning_allowance'], 'at least one warning is always allowed');
$test->assertSame('08:00', system_settings_clamp_time('08:00:00', '00:00'), 'a MySQL TIME value is accepted');
$test->assertSame(0.5, system_settings_vehicle_multiplier('motorcycle', $db), 'the motorcycle multiplier is read from settings');

// ------------------------------------------------------------- no-show ----
$test->group('reservation expiry');

with_settings(['reservation_grace_minutes' => 30]);

$arrival = '2026-09-01 18:00:00';
$booking = [
    'barcode_status' => 'active',
    'reservation_date' => '2026-09-01',
    'reserved_time_in' => '18:00:00',
    'created_at' => '2026-09-01 08:00:00'
];

$test->assertSame(
    strtotime('2026-09-01 18:30:00'),
    reservation_security_deadline_timestamp($booking, 30),
    'the deadline is the arrival time plus the grace period'
);

$test->assertSame(
    false,
    reservation_security_reservation_is_due_for_expiration($booking, strtotime('2026-09-01 09:00:00'), 30),
    'a booking made this morning for tonight is not expired at 09:00'
);

$test->assertSame(
    false,
    reservation_security_reservation_is_due_for_expiration($booking, strtotime($arrival), 30),
    'a booking is still valid at its arrival time'
);

$test->assertTrue(
    reservation_security_reservation_is_due_for_expiration($booking, strtotime('2026-09-01 18:31:00'), 30),
    'a booking expires once the grace period passes'
);

$test->assertSame(
    false,
    reservation_security_reservation_is_due_for_expiration(
        array_merge($booking, ['actual_time_in' => '2026-09-01 18:05:00']),
        strtotime('2026-09-01 23:00:00'),
        30
    ),
    'a booking that was scanned never expires'
);

$test->assertSame(
    false,
    reservation_security_reservation_is_due_for_expiration(
        array_merge($booking, ['barcode_status' => 'cancelled']),
        strtotime('2026-09-02 10:00:00'),
        30
    ),
    'a cancelled booking is not expired again'
);

$legacy = ['barcode_status' => 'active', 'created_at' => '2026-09-01 08:00:00'];
$test->assertTrue(
    reservation_security_reservation_is_due_for_expiration($legacy, strtotime('2026-09-01 08:31:00'), 30),
    'a legacy row with no arrival time still expires from created_at'
);

// ------------------------------------------------------------------ CORS --
$test->group('CorsHelper');

$_SERVER['HTTP_ORIGIN'] = 'http://localhost';
$test->assertSame('http://localhost', CorsHelper::resolveOrigin(), 'an allowed origin is echoed back');

$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
$test->assertTrue(
    CorsHelper::resolveOrigin() !== 'https://evil.example.com',
    'an unknown origin is never echoed back'
);

unset($_SERVER['HTTP_ORIGIN']);
$test->assertTrue(CorsHelper::resolveOrigin() !== '*', 'the wildcard is never returned');

// ---------------------------------------------------------------- barcode --
$test->group('barcode normalisation');

$test->assertSame(
    booth_lookup_barcode('sp-lg-l2-02vh0de1'),
    booth_lookup_barcode('SP LG L2 02VH0DE1'),
    'case and spacing do not change the lookup key'
);

$test->assertTrue(booth_lookup_barcode('   ') === '', 'a blank barcode has no lookup key');

// ------------------------------------------------- admin error disclosure --
$test->group('admin error disclosure');

$driverFailure = new RuntimeException(
    "Unknown column 'staff_accounts.pin_hash' in 'field list'"
);

$debugEnabled = filter_var((string) EnvHelper::get('APP_DEBUG', ''), FILTER_VALIDATE_BOOLEAN);

if ($debugEnabled) {
    $test->assertSame(
        ['details' => $driverFailure->getMessage()],
        admin_debug_details($driverFailure),
        'with APP_DEBUG on the driver text is returned for local debugging'
    );
} else {
    $test->assertSame(
        [],
        admin_debug_details($driverFailure),
        'with APP_DEBUG off no schema detail reaches the client'
    );
}

// A refusal the endpoint authored itself is worth showing.
$test->assertSame(
    'Floor still has active reservations.',
    admin_safe_error_message(
        new RuntimeException('Floor still has active reservations.'),
        409,
        'Failed to delete floor.'
    ),
    'a 4xx keeps the message the endpoint wrote'
);

// Anything the driver threw is not.
$test->assertSame(
    'Failed to delete floor.',
    admin_safe_error_message($driverFailure, 500, 'Failed to delete floor.'),
    'a 5xx is replaced with the generic fallback'
);

$test->assertSame(
    'Failed to delete slot.',
    admin_safe_error_message(new RuntimeException(''), 422, 'Failed to delete slot.'),
    'an empty message falls back rather than returning a blank error'
);

$test->assertSame(
    1800,
    ADMIN_SESSION_TIMEOUT,
    'the admin idle timeout stays at 30 minutes'
);

// --------------------------------------------------- admin change alerts --
$test->group('admin change alerts');

// The three areas the alert is meant to cover, one action from each.
$alertable = admin_alertable_actions();

$test->assertTrue(
    isset($alertable['ADMIN_SETTINGS_UPDATED']),
    'a settings change is alertable'
);
$test->assertTrue(
    isset($alertable['ADMIN_USER_DELETED']),
    'deleting a user is alertable'
);
$test->assertTrue(
    isset($alertable['ADMIN_STAFF_CREATED']),
    'creating a booth teller is alertable'
);

// Every action the endpoints actually raise for those three areas, so adding
// a new one without adding its alert shows up here rather than in production.
foreach ([
    'ADMIN_USER_UPDATED',
    'ADMIN_USER_DISABLED',
    'ADMIN_USER_ACTIVATED',
    'ADMIN_USER_UNLOCKED',
    'ADMIN_STAFF_UPDATED',
    'ADMIN_STAFF_DELETED'
] as $actionType) {
    $test->assertTrue(isset($alertable[$actionType]), $actionType . ' is alertable');
}

// Noisy, reversible, and already visible in the audit log -- deliberately not
// mailed, so a busy shift does not bury the alerts that matter.
$test->assertSame(
    false,
    isset($alertable['ADMIN_LOGIN_SUCCESS']),
    'a routine admin login is not mailed'
);
$test->assertSame(
    false,
    isset($alertable['ADMIN_NOTIFICATION_CREATED']),
    'creating a notification is not mailed'
);

$defaults = system_settings_defaults();

$test->assertSame(
    1,
    $defaults['admin_change_alerts_enabled'],
    'change alerts are on by default'
);
$test->assertSame(
    0,
    $defaults['admin_2fa_enabled'],
    'two-factor stays off by default so a fresh install is not locked out'
);

// The toggles have to survive normalisation or the settings form cannot save
// them: an unknown key is dropped, and a bad value must clamp, not persist.
$normalized = system_settings_normalize([
    'admin_change_alerts_enabled' => '0',
    'admin_2fa_enabled' => '1'
]);

$test->assertSame(0, $normalized['admin_change_alerts_enabled'], 'change alerts can be switched off');
$test->assertSame(1, $normalized['admin_2fa_enabled'], 'two-factor can be switched on');

$clamped = system_settings_normalize(['admin_change_alerts_enabled' => '7']);
$test->assertSame(1, $clamped['admin_change_alerts_enabled'], 'an out-of-range toggle clamps to on/off');

// ------------------------------------------------------- admin credentials --
$test->group('admin credentials');

// The self-service endpoint writes with admin_staff_password_hash() and reads
// with admin_staff_password_verify(), so those two have to agree.
$hash = admin_staff_password_hash('C0rrect-Horse-Battery!');

$test->assertTrue(
    strlen($hash) === 60 && str_starts_with($hash, '$2y$'),
    'a new admin password is stored as bcrypt, not sha256'
);
$test->assertTrue(
    admin_staff_password_verify('C0rrect-Horse-Battery!', $hash),
    'the password it just wrote verifies'
);
$test->assertSame(
    false,
    admin_staff_password_verify('c0rrect-horse-battery!', $hash),
    'a near miss is refused'
);

// Accounts seeded before the migration still hold an unsalted sha256. They
// have to keep working or changing the password becomes impossible -- you
// cannot prove your current password to an endpoint that will not check it.
$legacy = hash('sha256', 'Admin123!');

$test->assertTrue(
    admin_staff_password_verify('Admin123!', $legacy),
    'a legacy sha256 password still verifies'
);
$test->assertSame(
    false,
    admin_staff_password_verify('wrong', $legacy),
    'a legacy hash still refuses the wrong password'
);

$accountAlerts = admin_alertable_actions();

$test->assertTrue(
    isset($accountAlerts['ADMIN_ACCOUNT_UPDATED']),
    'changing your own sign-in details is alertable'
);
// Refusals are logged, never mailed: the send is inline, so alerting here
// made every mistyped password wait on SMTP.
$test->assertSame(
    false,
    isset($accountAlerts['ADMIN_ACCOUNT_UPDATE_FAILED']),
    'a refused account change is logged but not mailed'
);

// The policy is what stops the new password being as guessable as the one it
// replaces, so a couple of the obvious rejections are pinned here.
$weak = PasswordPolicy::check('admin', []);
$test->assertTrue($weak !== [], 'a short all-lowercase password is refused');

$personal = PasswordPolicy::check('Sndrapark2026!', PasswordPolicy::contextFromUser([
    'full_name' => 'Sndrapark Administrator',
    'email' => 'admin@sndrapark.com'
]));
$test->assertTrue($personal !== [], 'a password built from the account name is refused');

$test->assertSame(
    [],
    PasswordPolicy::check('7yQ!ravenLoop#2026', []),
    'a strong password passes'
);

// ------------------------------------------------------- admin login trap --
$test->group('admin login trap');

require_once __DIR__ . '/../backend/admin/security-trap.php';

// The markup in admin-login.html and the JS payload both carry this name; if
// it moves in one place and not the others the wire is silently dead.
$test->assertSame(
    'admin_notes_hp',
    admin_trap_field_name(),
    'the bait field name is the one the sign-in form posts'
);

// Named so a password manager has no reason to fill it. Anything that looks
// like a credential field risks blocking a real administrator.
foreach (['email', 'user', 'name', 'pass', 'login'] as $credentialWord) {
    $test->assertSame(
        false,
        str_contains(admin_trap_field_name(), $credentialWord),
        'the bait name avoids "' . $credentialWord . '", which autofill targets'
    );
}

$test->assertSame(
    3600,
    ADMIN_TRAP_BLOCK_SECONDS,
    'a tripped address is refused for an hour'
);

// A trip is recorded with status failure, so it has to be on the list that
// survives the success-only rule -- otherwise it would log but never alert.
$trapAlerts = admin_alertable_actions();

$test->assertTrue(
    isset($trapAlerts['ADMIN_HONEYPOT_TRIPPED']),
    'a tripped trap is mailed, not just logged'
);

// ------------------------------------------------------ human verification --
$test->group('human verification');

require_once __DIR__ . '/../backend/utils/HumanCheck.php';

$test->assertSame(
    'extra_notes_hp',
    HumanCheck::fieldName(),
    'the bait field name is the one signup.html posts'
);

foreach (['email', 'user', 'name', 'pass', 'phone'] as $credentialWord) {
    $test->assertSame(
        false,
        str_contains(HumanCheck::fieldName(), $credentialWord),
        'the bait name avoids "' . $credentialWord . '", which autofill targets'
    );
}

/** Read the answer back out of the question a person would be shown. */
$solve = static function (string $question): int {
    $words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six',
        'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
    preg_match('/What is (\w+) (plus|minus) (\w+)\?/', $question, $parts);
    $left = array_search($parts[1], $words, true);
    $right = array_search($parts[3], $words, true);

    return $parts[2] === 'plus' ? $left + $right : $left - $right;
};

/** Issue a challenge and pretend the form has been open a while. */
$openChallenge = static function () use ($solve): int {
    $challenge = HumanCheck::issue();
    $_SESSION['_human_check']['issued_at'] = time() - 60;

    return $solve($challenge['question']);
};

// The question is spelled out, so a solver cannot just eval the digits.
$issued = HumanCheck::issue();
$test->assertSame(
    1,
    preg_match('/^What is [a-z]+ (plus|minus) [a-z]+\?$/', $issued['question']),
    'the question spells its numbers out rather than printing digits'
);

// The answer must never travel to the client.
$test->assertSame(
    false,
    array_key_exists('answer', $issued),
    'issuing a challenge does not hand out its answer'
);

$answer = $openChallenge();
$accepted = true;

try {
    HumanCheck::verify(['humanCheckAnswer' => (string) $answer]);
} catch (InvalidArgumentException $exception) {
    $accepted = false;
}

$test->assertTrue($accepted, 'the correct answer is accepted');

// Single use: the same solved challenge must not open a second account.
$replayed = false;

try {
    HumanCheck::verify(['humanCheckAnswer' => (string) $answer]);
    $replayed = true;
} catch (InvalidArgumentException $exception) {
    $replayed = false;
}

$test->assertSame(false, $replayed, 'a solved challenge cannot be replayed');

// A word is the same answer as a digit to the person reading the question.
$words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six',
    'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
$answer = $openChallenge();
$wordAccepted = true;

try {
    HumanCheck::verify(['humanCheckAnswer' => $words[$answer] ?? (string) $answer]);
} catch (InvalidArgumentException $exception) {
    $wordAccepted = false;
}

$test->assertTrue($wordAccepted, 'the answer spelled as a word is accepted too');

// The bait outranks everything: a correct answer does not rescue it.
$answer = $openChallenge();
$baitMessage = '';

try {
    HumanCheck::verify([
        'humanCheckAnswer' => (string) $answer,
        HumanCheck::fieldName() => 'http://spam.example'
    ]);
} catch (InvalidArgumentException $exception) {
    $baitMessage = $exception->getMessage();
}

$test->assertSame(
    'We could not verify this submission. Please try again.',
    $baitMessage,
    'a filled bait is refused even with the right answer, and is not told why'
);

// Submitted the instant the question was issued.
HumanCheck::issue();
$fastMessage = '';

try {
    HumanCheck::verify(['humanCheckAnswer' => '5']);
} catch (InvalidArgumentException $exception) {
    $fastMessage = $exception->getMessage();
}

$test->assertSame(
    'That was submitted too quickly. Please try again.',
    $fastMessage,
    'a form completed faster than a person could is refused'
);

// Three wrong answers spend the challenge.
$answer = $openChallenge();
$wrong = $answer === 99 ? 98 : 99;

for ($attempt = 1; $attempt <= 2; $attempt++) {
    try {
        HumanCheck::verify(['humanCheckAnswer' => (string) $wrong]);
    } catch (InvalidArgumentException $exception) {
        // expected
    }
}

$thirdMessage = '';

try {
    HumanCheck::verify(['humanCheckAnswer' => (string) $wrong]);
} catch (InvalidArgumentException $exception) {
    $thirdMessage = $exception->getMessage();
}

$test->assertSame(
    'That answer is not correct. Refresh the page for a new question.',
    $thirdMessage,
    'the third wrong answer spends the challenge'
);

unset($_SESSION['_human_check']);

// ------------------------------------------------------------------ csrf --

$test->group('csrf guard');

/** Drive CsrfGuard as if a request had arrived with these properties. */
$asRequest = static function (string $method, string $uri, array $query = []): void {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_GET = $query;
};

$asRequest('GET', '/sndraPark/backend/user/get_reservations.php');
$test->assertSame(false, CsrfGuard::isUnsafeRequest(), 'GET is a safe method');

$asRequest('HEAD', '/sndraPark/backend/user/get_reservations.php');
$test->assertSame(false, CsrfGuard::isUnsafeRequest(), 'HEAD is a safe method');

foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $unsafeMethod) {
    $asRequest($unsafeMethod, '/sndraPark/backend/user/submit_reservation.php');
    $test->assertTrue(
        CsrfGuard::isUnsafeRequest(),
        strtolower($unsafeMethod) . ' changes state and needs a token'
    );
}

// Sign-in endpoints are reached before there is a session to ride.
$exemptPaths = [
    '/sndraPark/backend/api/v1/login',
    '/sndraPark/backend/api/v1/register',
    '/sndraPark/backend/admin/login.php',
    '/sndraPark/backend/parking-booth/login.php',
    '/sndraPark/backend/auth/csrf-token.php',
    '/sndraPark/backend/user/submit-appeal.php',
    '/sndraPark/send-reset-otp.php',
    '/sndraPark/reset-password.php'
];

foreach ($exemptPaths as $exemptPath) {
    $asRequest('POST', $exemptPath);
    $test->assertTrue(
        CsrfGuard::isExemptRequest(),
        'no token is demanded of ' . basename($exemptPath)
    );
}

// Everything that acts on a signed-in session is not exempt.
$guardedPaths = [
    '/sndraPark/backend/api/v1/users/update',
    '/sndraPark/backend/api/v1/reservations',
    '/sndraPark/backend/user/cancel_reservation.php',
    '/sndraPark/backend/user/submit_reservation.php',
    '/sndraPark/backend/parking-booth/payment.php',
    '/sndraPark/backend/parking-booth/pay.php',
    '/sndraPark/backend/parking-booth/scan.php',
    '/sndraPark/backend/parking-booth/walkin.php',
    '/sndraPark/backend/notifications/mark-read.php',
    '/sndraPark/backend/feedback/submit.php'
];

foreach ($guardedPaths as $guardedPath) {
    $asRequest('POST', $guardedPath);
    $test->assertSame(
        false,
        CsrfGuard::isExemptRequest(),
        basename($guardedPath) . ' is not exempt'
    );
}

// The exemption is anchored at the end of the path, so a scanner cannot append
// an exempt-looking name to reach a guarded endpoint.
$asRequest('POST', '/sndraPark/backend/parking-booth/payment.php/login.php');
$test->assertSame(
    false,
    CsrfGuard::isExemptRequest(),
    'an exempt name in the middle of a path does not exempt the request'
);

$asRequest('POST', '/sndraPark/backend/user/cancel_reservation.php', ['action' => 'login']);
$test->assertSame(
    false,
    CsrfGuard::isExemptRequest(),
    'action=login does not exempt an endpoint outside the API router'
);

// ...but the router form, where the action never reaches the path, still does.
$asRequest('POST', '/sndraPark/backend/api/v1/index.php', ['action' => 'login']);
$test->assertTrue(
    CsrfGuard::isExemptRequest(),
    'the api/v1 router recognises action=login'
);

// A safe method is waved through without a token even where it is not exempt.
$asRequest('GET', '/sndraPark/backend/api/v1/reservations');
$rejectedSafeRequest = false;

try {
    CsrfGuard::requireValidToken();
} catch (RuntimeException $exception) {
    $rejectedSafeRequest = true;
}

$test->assertSame(false, $rejectedSafeRequest, 'a GET is never asked for a token');

// A guarded POST with no token is refused, as 403 rather than the middleware's
// native 419 -- Apache has no reason phrase for 419 and rewrites it to 500.
$asRequest('POST', '/sndraPark/backend/user/cancel_reservation.php');
unset($_POST['_csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
$_SESSION['_csrf_token'] = ['token' => str_repeat('a', 64), 'created_at' => time()];

$refusalCode = 0;

try {
    CsrfGuard::requireValidToken();
} catch (RuntimeException $exception) {
    $refusalCode = (int) $exception->getCode();
}

$test->assertSame(403, $refusalCode, 'a missing token is refused with 403');

// A wrong token is refused the same way a missing one is.
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);
$mismatchCode = 0;

try {
    CsrfGuard::requireValidToken();
} catch (RuntimeException $exception) {
    $mismatchCode = (int) $exception->getCode();
}

$test->assertSame(403, $mismatchCode, 'a token that does not match is refused');

// The right token is accepted.
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
$acceptedValidToken = true;

try {
    CsrfGuard::requireValidToken();
} catch (RuntimeException $exception) {
    $acceptedValidToken = false;
}

$test->assertTrue($acceptedValidToken, 'the session token is accepted');

unset($_SESSION['_csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);

// --------------------------------------------------- legacy password hash --

$test->group('legacy staff password hashes');

$legacyHash = hash('sha256', 'CorrectHorse1!');

$test->assertTrue(
    admin_staff_password_is_legacy_hash($legacyHash),
    'a bare sha256 digest is recognised as legacy'
);

$test->assertSame(
    false,
    admin_staff_password_is_legacy_hash(password_hash('CorrectHorse1!', PASSWORD_DEFAULT)),
    'a password_hash() value is not treated as legacy'
);

$test->assertTrue(
    admin_staff_password_verify('CorrectHorse1!', $legacyHash),
    'a legacy password still verifies, so no one is locked out mid-migration'
);

$test->assertSame(
    false,
    admin_staff_password_verify('WrongHorse1!', $legacyHash),
    'a wrong password against a legacy hash is refused'
);

$test->assertTrue(
    admin_staff_password_verify('CorrectHorse1!', admin_staff_password_hash('CorrectHorse1!')),
    'a re-hashed password verifies through the modern branch'
);

// ------------------------------------------------------ slot sensor rig --
$test->group('sensor line protocol');

$goodFrame = parking_sensor_build_frame('UNO-A', 1, 'F', '2=0,3=1');

$test->assertSame(
    ['device_id' => 'UNO-A', 'seq' => 1, 'type' => 'F', 'payload' => '2=0,3=1', 'readings' => [2 => 0, 3 => 1]],
    parking_sensor_parse_line($goodFrame),
    'a well-formed frame parses into its readings'
);

$test->assertSame(
    null,
    parking_sensor_parse_line(str_replace('SP1|', 'SP2|', $goodFrame)),
    'a frame from an unknown protocol version is refused'
);

$test->assertSame(
    null,
    parking_sensor_parse_line('3|1|F|2=0|7A'),
    'the half-line you always get on the first read after opening a port is refused'
);

$test->assertSame(
    null,
    parking_sensor_parse_line('SP1|UNO-A|1|F|2=0,3=1|00'),
    'a frame whose checksum does not match is refused'
);

$test->assertSame(
    null,
    parking_sensor_parse_line('SP1|UNO-A|1|F|2=0,3=1'),
    'a frame with no checksum field at all is refused'
);

$test->assertSame(
    null,
    parking_sensor_parse_line(parking_sensor_build_frame('UNO-A', 1, 'F', '2=2')),
    'a reading that is neither 0 nor 1 is refused'
);

$test->assertSame(
    null,
    parking_sensor_parse_line(parking_sensor_build_frame('UNO A', 1, 'F', '2=1')),
    'a device id with a space in it is refused rather than quietly rewritten'
);

$test->assertSame(
    null,
    parking_sensor_parse_line(parking_sensor_build_frame('UNO-A', 1, 'F', str_repeat('2=1,', 60))),
    'a line past the 200-byte ceiling is refused'
);

$emptyFrame = parking_sensor_parse_line(parking_sensor_build_frame('UNO-A', 7, 'F', ''));

$test->assertSame(
    [],
    $emptyFrame['readings'] ?? null,
    'a board with nothing mapped yet sends a valid frame with no readings'
);

$test->assertSame(
    ['device_id' => 'UNO-A', 'seq' => 1, 'type' => 'F', 'payload' => '2=0,3=1', 'readings' => [2 => 0, 3 => 1]],
    parking_sensor_parse_line($goodFrame . "\r\n"),
    'a trailing carriage return and newline are tolerated'
);

$test->assertSame(
    null,
    parking_sensor_parse_line(parking_sensor_build_frame('UNO-A', 1, 'F', '2=0,2=1')),
    'a frame that reports the same pin twice is refused'
);

$test->assertSame(
    ['fw' => '1.0', 'pins' => '2-13', 'kind' => 'IR'],
    parking_sensor_parse_hello('fw=1.0;pins=2-13;kind=IR'),
    'a boot greeting parses into its key and value pairs'
);

$test->group('sensor identity and checksum');

$test->assertSame(
    'UNO-A',
    parking_sensor_normalize_device_id('  uno-a  '),
    'a device id is trimmed and folded to upper case'
);

$test->assertSame(
    '',
    parking_sensor_normalize_device_id(str_repeat('A', 65)),
    'a device id past 64 characters is refused'
);

$test->assertSame(
    '',
    parking_sensor_normalize_device_id("UNO\x01A"),
    'a device id carrying a control character is refused'
);

$test->assertSame(
    '41',
    parking_sensor_checksum('A'),
    'the checksum of a single byte is that byte'
);

$test->assertSame(
    '00',
    parking_sensor_checksum('AA'),
    'a byte exclusive-ored with itself checksums to zero'
);

$test->group('sensor LCD frame');

$fourFloors = parking_sensor_build_lcd_frame([
    'LG' => 12,
    '1st Floor' => 8,
    '2nd Floor' => 20,
    '3rd Floor' => 3
]);

$test->assertSame(
    [16, 16],
    [strlen($fourFloors[0]), strlen($fourFloors[1])],
    'both LCD lines are exactly sixteen characters, so the sketch never formats'
);

$oneFloor = parking_sensor_build_lcd_frame(['LG' => 4]);

$test->assertSame(
    str_repeat(' ', 16),
    $oneFloor[1],
    'a rig with one floor pads the second line rather than sending a short one'
);

$test->assertSame(
    [16, 16],
    array_map('strlen', parking_sensor_build_lcd_frame([])),
    'a rig with no floor data still sends two full-width lines'
);

$manyFloors = parking_sensor_build_lcd_frame([
    'LG' => 1, '1st Floor' => 2, '2nd Floor' => 3, '3rd Floor' => 4, '4th Floor' => 5, '5th Floor' => 6
]);

$test->assertSame(
    [16, 16],
    array_map('strlen', $manyFloors),
    'a sixth floor cannot push the display past sixteen characters'
);

$test->assertSame(
    [16, 16],
    array_map('strlen', parking_sensor_build_lcd_frame(['LG' => 100, '1st Floor' => 100])),
    'a three-digit free count still fits its cell'
);

$test->assertSame(
    '1F',
    parking_sensor_lcd_abbreviate_floor('1st Floor'),
    'an ordinal floor name abbreviates the same way every refresh'
);

$test->assertSame(
    16 + 16 + strlen('SP1|LCD|||') + 2,
    strlen(parking_sensor_build_lcd_downlink('abc', 'def')),
    'a downlink frame pads both lines to full width before framing them'
);

$test->group('sensor staleness');

$test->assertSame(
    'none',
    parking_sensor_classify_reading(false, true, true, 1, 120),
    'a bay with no sensor mapped reports none'
);

$test->assertSame(
    'disabled',
    parking_sensor_classify_reading(true, false, true, 1, 120),
    'a sensor an admin switched off reports disabled, whatever it sees'
);

$test->assertSame(
    'offline',
    parking_sensor_classify_reading(true, true, true, null, 120),
    'a sensor that has never reported is offline, not vacant'
);

$test->assertSame(
    'occupied',
    parking_sensor_classify_reading(true, true, true, 120, 120),
    'a reading exactly at the staleness limit still counts as fresh'
);

$test->assertSame(
    'offline',
    parking_sensor_classify_reading(true, true, true, 121, 120),
    'one second past the limit the reading is discarded'
);

$test->assertSame(
    'vacant',
    parking_sensor_classify_reading(true, true, false, 5, 120),
    'a fresh sensor seeing nothing reports vacant'
);

$test->group('slot live status precedence');

$test->assertSame(
    'Inactive',
    parking_resolve_live_status(['floor_is_active' => 0, 'is_active' => 1, 'active_rank' => 1, 'sensor_state' => 'occupied']),
    'a closed floor outranks everything, sensor included'
);

$test->assertSame(
    'Inactive',
    parking_resolve_live_status(['floor_is_active' => 1, 'is_active' => 0, 'sensor_state' => 'occupied']),
    'a bay taken out of service outranks its sensor'
);

$test->assertSame(
    'Inactive',
    parking_resolve_live_status(['manual_status' => 'Inactive', 'sensor_state' => 'occupied']),
    'an admin marking a bay inactive outranks its sensor'
);

$test->assertSame(
    'Occupied',
    parking_resolve_live_status(['active_rank' => 2, 'sensor_state' => 'vacant']),
    'a booth check-in outranks a sensor that has not noticed the car yet'
);

$test->assertSame(
    'Occupied',
    parking_resolve_live_status(['active_rank' => 0, 'sensor_state' => 'occupied']),
    'a car parked with no booking at all still shows the bay as taken'
);

$test->assertSame(
    'Occupied',
    parking_resolve_live_status(['active_rank' => 1, 'sensor_state' => 'occupied']),
    'a car in a bay somebody else is holding shows as taken, not reserved'
);

$test->assertTrue(
    parking_sensor_is_mismatch('occupied', 1),
    'that same case is what staff see flagged as a mismatch'
);

$test->assertSame(
    false,
    parking_sensor_is_mismatch('occupied', 2),
    'once the driver is scanned in the car in the bay is the expected car'
);

$test->assertSame(
    'Reserved',
    parking_resolve_live_status(['active_rank' => 1, 'sensor_state' => 'offline']),
    'when the rig goes quiet a held bay degrades to reservation truth'
);

$test->assertSame(
    'Available',
    parking_resolve_live_status(['active_rank' => 0, 'sensor_state' => 'offline']),
    'a dead bridge frees the board rather than freezing it'
);

$test->assertSame(
    'Available',
    parking_resolve_live_status(['active_rank' => 0, 'sensor_state' => 'disabled', 'manual_status' => 'Auto']),
    'disabling a stuck sensor is the escape hatch that frees its bay'
);

$test->assertSame(
    'Occupied',
    parking_resolve_live_status(['active_rank' => 0, 'manual_status' => 'Occupied', 'sensor_state' => 'none']),
    'an admin override still decides a bay that has no sensor'
);

$test->assertSame(
    'Occupied',
    parking_resolve_live_status(['active_rank' => 0, 'manual_status' => 'Available', 'sensor_state' => 'occupied']),
    'a sensor seeing a car overrules an admin who marked the bay available'
);

$test->group('sensor debounce');

$state = ['stable' => 0, 'candidate' => 0, 'count' => 0];

for ($tick = 1; $tick < 5; $tick++) {
    $state = parking_sensor_debounce_step($state, 1);
}

$test->assertSame(
    0,
    $state['stable'],
    'four samples are not enough to flip a bay that needs five'
);

$state = parking_sensor_debounce_step($state, 1);

$test->assertSame(
    1,
    $state['stable'],
    'the fifth consecutive sample flips it'
);

$test->assertTrue(
    $state['changed'],
    'and the flip is reported so the board can send an edge'
);

$state = ['stable' => 0, 'candidate' => 1, 'count' => 3];
$state = parking_sensor_debounce_step($state, 0);

$test->assertSame(
    0,
    $state['count'],
    'a single dissenting sample restarts the run, so beam noise cannot walk a bay across'
);

$test->group('plain text sensor lines');

$test->assertSame(
    ['occupied' => 0, 'distance_cm' => 175.92],
    parking_sensor_parse_plain_line('Distance: 175.92 cm | AVAILABLE'),
    'a stock sketch line reporting a clear bay is understood without reflashing'
);

$test->assertSame(
    ['occupied' => 1, 'distance_cm' => 12.4],
    parking_sensor_parse_plain_line('Distance: 12.40 cm | OCCUPIED'),
    'and so is one reporting a vehicle'
);

$test->assertSame(
    0,
    parking_sensor_parse_plain_line('Distance: 999.00 cm | AVAILABLE')['occupied'],
    'the board reports its own verdict, so a no-echo sentinel still reads as clear'
);

$test->assertSame(
    1,
    parking_sensor_parse_plain_line('Distance: 45.00 cm')['occupied'],
    'with no status word a close reading falls back to the threshold'
);

$test->assertSame(
    0,
    parking_sensor_parse_plain_line('Distance: 999.00 cm')['occupied'],
    'and a no-echo sentinel is never mistaken for a car parked far away'
);

$test->assertSame(
    null,
    parking_sensor_parse_plain_line('Setting up sensors...'),
    'a boot message is not a reading'
);

$test->assertSame(
    null,
    parking_sensor_parse_plain_line('A = AVAILABLE'),
    'a help line naming a status is prose, not a measurement'
);

$test->assertSame(
    null,
    parking_sensor_parse_plain_line('R = RESERVED'),
    'and neither is the rest of the boot banner'
);

$test->assertSame(
    null,
    parking_sensor_parse_plain_line('OCCUPIED'),
    'a bare status word with no distance is refused, so a banner cannot flap a bay'
);

$test->assertSame(
    null,
    parking_sensor_parse_plain_line('   '),
    'a blank line is not a reading'
);

$test->assertSame(
    1,
    parking_sensor_parse_plain_line('Distance: 80.00 cm', 90.0)['occupied'],
    'the threshold is configurable for a bay mounted closer than the default'
);

$test->group('one-bay reserved lamp downlink');

$test->assertSame(
    'R',
    parking_sensor_build_slot_downlink('Reserved'),
    'a held bay lights the reserved lamp'
);

$test->assertSame(
    'A',
    parking_sensor_build_slot_downlink('Available'),
    'a free bay clears the reserved lamp'
);

$test->assertSame(
    'A',
    parking_sensor_build_slot_downlink('Occupied'),
    'an occupied bay sends no reserved lamp: the board is already red from its own sensor'
);

$test->assertSame(
    'R',
    parking_sensor_build_slot_downlink('Inactive'),
    'a bay out of service shows amber, because green would invite a driver into it'
);

$test->assertSame(
    'R',
    parking_sensor_build_slot_downlink('  reserved  '),
    'the status is read the way the column spells it, whatever the casing and padding'
);

$test->assertSame(
    'A',
    parking_sensor_build_slot_downlink('Pending'),
    'a status this rig does not know fails safe to available, not to a lamp nobody can clear'
);

$test->assertSame(
    1,
    strlen(parking_sensor_build_slot_downlink('Reserved')),
    'the command is one character, because the sketch reads one character per loop'
);

$test->assertSame(
    true,
    strpbrk(parking_sensor_build_lcd_downlink('SNDRA Park', 'LG 12  1F 08'), 'RA') !== false,
    'an LCD frame carries the very characters the lamp commands use, which is why a board gets one downlink mode or the other'
);

$test->group('board chatter is not a reject');

$test->assertSame(
    true,
    parking_sensor_is_board_chatter('>>> SLOT AVAILABLE'),
    'the acknowledgement the board sends back for every downlink is not a corrupt line'
);

$test->assertSame(
    true,
    parking_sensor_is_board_chatter('>>> SLOT RESERVED'),
    'and neither is the reserved acknowledgement'
);

$test->assertSame(
    true,
    parking_sensor_is_board_chatter('================================'),
    'the boot banner rule is chatter'
);

$test->assertSame(
    true,
    parking_sensor_is_board_chatter('R = RESERVED'),
    'so is the help text listing the downlink commands'
);

$test->assertSame(
    false,
    parking_sensor_is_board_chatter('Distance: 12.40 cm | OCCUPIED'),
    'a real reading is never chatter'
);

$test->assertSame(
    false,
    parking_sensor_is_board_chatter('SP1|UNO-A|1|F|2=0|XX'),
    'a framed line that failed its checksum is still a reject, which is the point of the counter'
);

$test->assertSame(
    false,
    parking_sensor_is_board_chatter('Dist>>> garbled'),
    'the acknowledgement is recognised at the start of a line only, so a collision cannot hide corruption'
);

$test->assertSame(
    true,
    parking_sensor_is_board_chatter('SP1|LOCAL|0|IDLE||00'),
    'the helper idle sentinel is ours, so a quiet port costs no rejects and no log spam'
);

$test->assertSame(
    false,
    parking_sensor_is_board_chatter('SP1|LOCAL|1|F|2=1|7A'),
    'a real frame from a board calling itself LOCAL is still read, because the sentinel matches exactly'
);

$test->assertSame(
    false,
    parking_sensor_is_board_chatter(''),
    'a blank line is handled by the caller, not silenced here'
);

exit($test->summary());
