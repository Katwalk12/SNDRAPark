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

exit($test->summary());
