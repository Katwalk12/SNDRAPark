<?php

declare(strict_types=1);

/**
 * Wire protocol and classification helpers for the Arduino slot-sensor rig.
 *
 * Everything in this file is pure: no mysqli, no session, no I/O, no side
 * effects on include. tests/run.php loads it directly and the suite is
 * database-free, so a stray require of config/db.php here would break CI.
 *
 * Time never appears in this file either. Staleness arrives as an integer age
 * that MySQL computed with TIMESTAMPDIFF(SECOND, ..., NOW()), because PHP is
 * pinned to Asia/Manila by config/app.php while MariaDB runs on the Windows
 * system zone and nothing reconciles the two.
 */

if (!defined('SENSOR_PROTOCOL_MAGIC')) {
    define('SENSOR_PROTOCOL_MAGIC', 'SP1');
}

if (!defined('SENSOR_LINE_MAX_BYTES')) {
    define('SENSOR_LINE_MAX_BYTES', 200);
}

if (!defined('SENSOR_LCD_WIDTH')) {
    define('SENSOR_LCD_WIDTH', 16);
}

if (!defined('SENSOR_DEBOUNCE_SAMPLES')) {
    define('SENSOR_DEBOUNCE_SAMPLES', 5);
}

if (!function_exists('parking_sensor_normalize_device_id')) {
    /**
     * Fold a device id to its canonical form, or return '' if it is not one.
     *
     * The id reaches us over a serial line that also uses '|' as its delimiter,
     * so anything outside [A-Z0-9_-] is rejected rather than stripped: a board
     * sending a malformed id is misconfigured, and silently rewriting it to
     * something that happens to match a real mapping is how a mis-flashed rig
     * takes over another rig's bays.
     */
    function parking_sensor_normalize_device_id(string $deviceId): string
    {
        $candidate = strtoupper(trim($deviceId));

        if ($candidate === '' || strlen($candidate) > 64) {
            return '';
        }

        if (preg_match('/^[A-Z0-9_-]+$/', $candidate) !== 1) {
            return '';
        }

        return $candidate;
    }
}

if (!function_exists('parking_sensor_checksum')) {
    /**
     * XOR-8 of a frame body, as two uppercase hex digits.
     *
     * USB CDC already checksums the link, so this is not about bit flips. It
     * catches a line cut short by a brownout or a mid-transmission board reset,
     * which the magic prefix alone would happily let through.
     */
    function parking_sensor_checksum(string $body): string
    {
        $checksum = 0;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $checksum ^= ord($body[$index]);
        }

        return strtoupper(str_pad(dechex($checksum & 0xFF), 2, '0', STR_PAD_LEFT));
    }
}

if (!function_exists('parking_sensor_build_frame')) {
    /**
     * Build an uplink frame. Used by the simulator and by the protocol tests;
     * the sketch builds the same bytes with snprintf.
     */
    function parking_sensor_build_frame(string $deviceId, int $sequence, string $type, string $payload): string
    {
        $body = $deviceId . '|' . $sequence . '|' . $type . '|' . $payload;

        return SENSOR_PROTOCOL_MAGIC . '|' . $body . '|' . parking_sensor_checksum($body);
    }
}

if (!function_exists('parking_sensor_parse_line')) {
    /**
     * Parse one uplink line, or return null if it is not a frame we trust.
     *
     * Returning null rather than throwing is deliberate: the first read after
     * opening a serial port reliably hands back a half-line, and a board that
     * resets mid-transmission produces another. Those are routine, not
     * exceptional -- the bridge counts them and moves on.
     *
     * @return array{device_id:string,seq:int,type:string,payload:string,readings:array<int,int>}|null
     */
    function parking_sensor_parse_line(string $line): ?array
    {
        $line = trim($line, "\r\n");

        if ($line === '' || strlen($line) > SENSOR_LINE_MAX_BYTES) {
            return null;
        }

        $parts = explode('|', $line);

        if (count($parts) !== 6) {
            return null;
        }

        [$magic, $deviceId, $sequence, $type, $payload, $checksum] = $parts;

        if ($magic !== SENSOR_PROTOCOL_MAGIC) {
            return null;
        }

        $body = $deviceId . '|' . $sequence . '|' . $type . '|' . $payload;

        if (strtoupper(trim($checksum)) !== parking_sensor_checksum($body)) {
            return null;
        }

        $normalizedDevice = parking_sensor_normalize_device_id($deviceId);

        if ($normalizedDevice === '') {
            return null;
        }

        if (preg_match('/^\d{1,5}$/', $sequence) !== 1) {
            return null;
        }

        if (preg_match('/^[A-Z]{1,8}$/', $type) !== 1) {
            return null;
        }

        $readings = [];

        if ($type === 'F' || $type === 'S') {
            $readings = parking_sensor_parse_readings($payload);

            if ($readings === null) {
                return null;
            }
        }

        return [
            'device_id' => $normalizedDevice,
            'seq' => (int) $sequence,
            'type' => $type,
            'payload' => $payload,
            'readings' => $readings
        ];
    }
}

if (!function_exists('parking_sensor_parse_readings')) {
    /**
     * Turn "2=0,3=1" into [2 => 0, 3 => 1]. Null means the payload is malformed.
     *
     * An empty payload is valid and yields an empty array: a board with nothing
     * mapped yet is a legitimate state, not an error.
     *
     * @return array<int,int>|null
     */
    function parking_sensor_parse_readings(string $payload): ?array
    {
        $payload = trim($payload);

        if ($payload === '') {
            return [];
        }

        $readings = [];

        foreach (explode(',', $payload) as $pair) {
            if (preg_match('/^(\d{1,3})=([01])$/', trim($pair), $matches) !== 1) {
                return null;
            }

            $pin = (int) $matches[1];

            if (isset($readings[$pin])) {
                return null;
            }

            $readings[$pin] = (int) $matches[2];
        }

        return $readings;
    }
}

if (!function_exists('parking_sensor_parse_hello')) {
    /**
     * Parse a HELLO payload such as "fw=1.0;pins=2-13;kind=IR" into a key map.
     *
     * @return array<string,string>
     */
    function parking_sensor_parse_hello(string $payload): array
    {
        $details = [];

        foreach (explode(';', trim($payload)) as $pair) {
            $pair = trim($pair);

            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = strtolower(trim($key));

            if ($key !== '') {
                $details[$key] = trim($value);
            }
        }

        return $details;
    }
}

if (!function_exists('parking_sensor_classify_reading')) {
    /**
     * Describe a sensor to the UI in one word.
     *
     * Takes an age already measured in SQL rather than a timestamp, which is
     * what keeps this function pure and free of the PHP/MariaDB zone mismatch.
     *
     * none     no sensor mapped to this bay at all
     * disabled mapped, but an admin switched it off (the stuck-sensor escape hatch)
     * offline  mapped and enabled, but nothing has reported recently
     * vacant   reporting, no vehicle
     * occupied reporting, vehicle present
     */
    function parking_sensor_classify_reading(
        bool $hasSensor,
        bool $isEnabled,
        bool $isOccupied,
        ?int $ageSeconds,
        int $staleAfterSeconds
    ): string {
        if (!$hasSensor) {
            return 'none';
        }

        if (!$isEnabled) {
            return 'disabled';
        }

        if ($ageSeconds === null || $ageSeconds > $staleAfterSeconds || $ageSeconds < 0) {
            return 'offline';
        }

        return $isOccupied ? 'occupied' : 'vacant';
    }
}

if (!function_exists('parking_sensor_state_is_live')) {
    /**
     * True when the sensor is actually reporting, whatever it is reporting.
     */
    function parking_sensor_state_is_live(string $sensorState): bool
    {
        return $sensorState === 'occupied' || $sensorState === 'vacant';
    }
}

if (!function_exists('parking_sensor_is_mismatch')) {
    /**
     * A vehicle is in a bay that somebody else is holding a reservation on.
     *
     * Only true while the hold is still just a hold: once the booth scans a
     * driver in (active_rank 2) the car in the bay is the expected car, and
     * there is nothing for staff to reconcile.
     */
    function parking_sensor_is_mismatch(string $sensorState, int $activeRank): bool
    {
        return $sensorState === 'occupied' && $activeRank === 1;
    }
}

if (!function_exists('parking_sensor_stale_after_seconds')) {
    /**
     * How old a reading may be before the bay falls back to reservation truth.
     *
     * The clamp is not decoration. This value is interpolated into the bulk
     * status UPDATE, which runs through mysqli::query() and not a prepared
     * statement, so the int cast and the range are the whole defence between a
     * config file and the query string.
     */
    function parking_sensor_stale_after_seconds(): int
    {
        if (isset($GLOBALS['__sensor_stale_after_seconds'])) {
            return max(15, min(3600, (int) $GLOBALS['__sensor_stale_after_seconds']));
        }

        $configured = 120;

        if (class_exists('EnvHelper')) {
            $configured = (int) EnvHelper::get('SENSOR_STALE_SECONDS', '120');
        }

        return max(15, min(3600, $configured));
    }
}

if (!function_exists('parking_resolve_live_status')) {
    /**
     * The single readable definition of what a bay is right now.
     *
     * Precedence, highest first:
     *   Inactive        an admin or a closed floor took the bay out of service
     *   booth check-in  a teller scanned a driver in, so billing owns the bay
     *   fresh sensor    a vehicle is physically there
     *   reservation     somebody holds it but has not arrived
     *   manual_status   an admin's standing override
     *   Available
     *
     * The sensor deliberately outranks both a reservation hold and
     * manual_status: the next driver needs to know a bay has a car in it,
     * whoever booked it. The escape hatch for a stuck sensor is disabling that
     * sensor row, which drops its bay straight back to reservation truth -- so
     * an admin is never left without a way to free a bay.
     *
     * A booth check-in still wins, because a sensor that has not noticed the
     * car yet must never contradict a transaction someone is being billed for.
     *
     * $row['sensor_state'] is what parking_sensor_classify_reading() returned.
     */
    function parking_resolve_live_status(array $row): string
    {
        $floorActive = (int) ($row['floor_is_active'] ?? 1) === 1;
        $slotActive = (int) ($row['is_active'] ?? 1) === 1;
        $manualStatus = (string) ($row['manual_status'] ?? 'Auto');
        $activeRank = (int) ($row['active_rank'] ?? 0);
        $sensorState = (string) ($row['sensor_state'] ?? 'none');

        if (!$floorActive || !$slotActive || $manualStatus === 'Inactive') {
            return 'Inactive';
        }

        if ($activeRank === 2) {
            return 'Occupied';
        }

        if ($sensorState === 'occupied') {
            return 'Occupied';
        }

        if ($activeRank === 1) {
            return 'Reserved';
        }

        if (in_array($manualStatus, ['Available', 'Reserved', 'Occupied'], true)) {
            return $manualStatus;
        }

        return 'Available';
    }
}

if (!function_exists('parking_sensor_debounce_step')) {
    /**
     * One debounce tick for one sensor.
     *
     * This is the executable spec for the matching loop in
     * hardware/sndra-slot-sensors: the sketch has no test harness, so the
     * algorithm is pinned here and the sketch comment points back at it.
     * A single dissenting sample restarts the run, so electrical noise on an IR
     * beam cannot walk a bay between states one sample at a time.
     *
     * @param array{stable:int,candidate:int,count:int} $state
     * @return array{stable:int,candidate:int,count:int,changed:bool}
     */
    function parking_sensor_debounce_step(array $state, int $sample, int $requiredSamples = SENSOR_DEBOUNCE_SAMPLES): array
    {
        $stable = (int) ($state['stable'] ?? 0);
        $candidate = (int) ($state['candidate'] ?? $stable);
        $count = (int) ($state['count'] ?? 0);
        $sample = $sample === 0 ? 0 : 1;
        $required = max(1, $requiredSamples);

        if ($sample === $stable) {
            return ['stable' => $stable, 'candidate' => $stable, 'count' => 0, 'changed' => false];
        }

        if ($sample === $candidate && $count > 0) {
            $count++;

            if ($count >= $required) {
                return ['stable' => $sample, 'candidate' => $sample, 'count' => 0, 'changed' => true];
            }

            return ['stable' => $stable, 'candidate' => $candidate, 'count' => $count, 'changed' => false];
        }

        return ['stable' => $stable, 'candidate' => $sample, 'count' => 1, 'changed' => false];
    }
}

if (!function_exists('parking_sensor_lcd_abbreviate_floor')) {
    /**
     * Squeeze a floor name into the handful of characters an LCD cell allows.
     *
     * "1st Floor" becomes "1F", "LG" stays "LG". Deterministic, because a label
     * that flickers between two abbreviations across refreshes reads as a fault.
     */
    function parking_sensor_lcd_abbreviate_floor(string $floorName, int $maxLength = 3): string
    {
        $name = strtoupper(trim($floorName));

        if ($name === '') {
            return '??';
        }

        if (preg_match('/^(\d+)\s*(?:ST|ND|RD|TH)?\s*FLOOR$/', $name, $matches) === 1) {
            $name = $matches[1] . 'F';
        }

        $name = preg_replace('/[^A-Z0-9]/', '', $name) ?? '';

        if ($name === '') {
            return '??';
        }

        return substr($name, 0, max(2, $maxLength));
    }
}

if (!function_exists('parking_sensor_build_lcd_frame')) {
    /**
     * Render free-slot counts onto two exactly-16-character LCD lines.
     *
     * PHP pads and truncates so the sketch does no formatting at all -- it just
     * setCursor and print. Two cells per line, four floors shown; a rig with
     * more floors than that needs a wider display, not a scrolling one.
     *
     * @param array<string,int> $freeByFloor floor name => free count
     * @return array{0:string,1:string}
     */
    function parking_sensor_build_lcd_frame(array $freeByFloor): array
    {
        $cells = [];

        foreach ($freeByFloor as $floorName => $freeCount) {
            $label = parking_sensor_lcd_abbreviate_floor((string) $floorName);
            $count = max(0, min(999, (int) $freeCount));
            $cells[] = str_pad($label . ' ' . $count, 8);

            if (count($cells) >= 4) {
                break;
            }
        }

        if ($cells === []) {
            return [
                str_pad('SNDRA Park', SENSOR_LCD_WIDTH),
                str_pad('No floor data', SENSOR_LCD_WIDTH)
            ];
        }

        $lines = [];

        for ($lineIndex = 0; $lineIndex < 2; $lineIndex++) {
            $left = $cells[$lineIndex * 2] ?? '';
            $right = $cells[($lineIndex * 2) + 1] ?? '';
            $lines[] = substr(str_pad($left . $right, SENSOR_LCD_WIDTH), 0, SENSOR_LCD_WIDTH);
        }

        return [$lines[0], $lines[1]];
    }
}

if (!function_exists('parking_sensor_build_lcd_downlink')) {
    /**
     * Wrap two rendered LCD lines in a downlink frame.
     *
     * No ACK is expected: the downlink is idempotent and resent every two
     * seconds, and flow control on this transport is not worth the complexity.
     */
    function parking_sensor_build_lcd_downlink(string $lineOne, string $lineTwo): string
    {
        $lineOne = substr(str_pad($lineOne, SENSOR_LCD_WIDTH), 0, SENSOR_LCD_WIDTH);
        $lineTwo = substr(str_pad($lineTwo, SENSOR_LCD_WIDTH), 0, SENSOR_LCD_WIDTH);
        $body = 'LCD|' . $lineOne . '|' . $lineTwo;

        return SENSOR_PROTOCOL_MAGIC . '|' . $body . '|' . parking_sensor_checksum($body);
    }
}

if (!function_exists('parking_sensor_parse_plain_line')) {
    /**
     * Read a human-readable sensor line, the kind a stock sketch already prints.
     *
     * A board flashed before this integration existed prints for people, not for
     * us -- "Distance: 175.92 cm | AVAILABLE" and nothing else. Reflashing it
     * just to satisfy a framed protocol would throw away working, tested
     * firmware, so the bridge meets it where it is.
     *
     * A line only counts as a reading if it carries a distance. A status word
     * on its own is prose, not a measurement -- boards print help text and
     * banners containing those same words, and treating those as readings makes
     * a bay flap every time the board resets.
     *
     * Given a distance, the status word wins when the sketch prints one,
     * because the board has already applied its own threshold and hysteresis
     * and knows its mounting better than we do.
     *
     * A plain line carries no identity, so the caller supplies the device and
     * pin. That is safe here and nowhere else: one bridge owns one port, so
     * there is exactly one board it could have come from.
     *
     * @return array{occupied:int,distance_cm:float|null}|null
     */
    function parking_sensor_parse_plain_line(string $line, float $occupiedBelowCm = 100.0): ?array
    {
        $line = trim($line);

        if ($line === '' || strlen($line) > SENSOR_LINE_MAX_BYTES) {
            return null;
        }

        $distance = null;

        if (preg_match('/(-?\d+(?:\.\d+)?)\s*cm/i', $line, $matches) === 1) {
            $distance = (float) $matches[1];
        }

        $status = null;

        if (preg_match('/\b(AVAILABLE|VACANT|FREE|EMPTY)\b/i', $line) === 1) {
            $status = 0;
        }

        if (preg_match('/\b(OCCUPIED|TAKEN|BUSY|PARKED)\b/i', $line) === 1) {
            $status = 1;
        }

        // A measurement, or nothing. Requiring the distance is what keeps the
        // board's own boot banner out of the data: this sketch prints a help
        // line reading "A = AVAILABLE", and taking that as a vacancy made the
        // bay flap between free and taken every time the board reset.
        if ($distance === null) {
            return null;
        }

        if ($status === null) {
            // An HC-SR04 that hears no echo reports a sentinel rather than a
            // reading -- 999 in the stock sketch. An empty bay is exactly what
            // no echo means, so treat anything implausible as clear rather than
            // letting a sentinel read as a car parked 999 cm away.
            $status = ($distance !== null && $distance > 0 && $distance < $occupiedBelowCm) ? 1 : 0;
        }

        return ['occupied' => $status, 'distance_cm' => $distance];
    }
}

if (!function_exists('parking_sensor_build_slot_downlink')) {
    /**
     * The single character a one-bay board understands, for one bay's status.
     *
     * A board like backend/iot/sensor.ino owns its own occupancy: an
     * ultrasonic ping tells it a car is there long before the server could,
     * and its red LED is wired to that directly. The one thing it cannot know
     * is whether somebody has *booked* the bay, so that is the only thing the
     * server sends -- 'R' to light the reserved lamp, 'A' to clear it.
     *
     * No frame, no checksum, no newline: the sketch reads one character per
     * loop and compares it. Wrapping this in SP1|...|crc would be worse than
     * useless -- the sketch would see the 'R' in "SNDRA" and the 'A' after it
     * and flap the lamp on every refresh. That is exactly why a board on this
     * downlink must never be sent the LCD frame, and why the mode is explicit
     * in the bridge rather than guessed from the traffic.
     *
     * Inactive maps to reserved rather than available on purpose. Green on a
     * bay an admin has taken out of service invites a driver into a space
     * nothing will bill or track; amber is the closest honest signal three
     * LEDs can give. Occupied needs no command at all -- the board is already
     * showing red from its own sensor, and if it is not, the server saying so
     * would not make the car appear.
     */
    function parking_sensor_build_slot_downlink(string $slotStatus): string
    {
        $status = parking_normalize_status_label($slotStatus);

        return ($status === 'Reserved' || $status === 'Inactive') ? 'R' : 'A';
    }
}

if (!function_exists('parking_normalize_status_label')) {
    /**
     * Fold a slot status to its canonical spelling.
     *
     * A local twin of parking_normalize_status() in backend/parking/common.php,
     * duplicated rather than required because this file is pure and the test
     * suite loads it without a database. Four strings that have not changed in
     * the lifetime of the schema are a cheaper dependency than dragging the
     * parking layer into a database-free test run.
     */
    function parking_normalize_status_label(string $status): string
    {
        $map = [
            'available' => 'Available',
            'reserved' => 'Reserved',
            'occupied' => 'Occupied',
            'inactive' => 'Inactive'
        ];

        return $map[strtolower(trim($status))] ?? 'Available';
    }
}

if (!function_exists('parking_sensor_is_board_chatter')) {
    /**
     * True for a line the board prints that was never meant to be a reading.
     *
     * Every unparseable line is charged to the device's reject counter, and
     * that counter earns its keep by revealing a loose USB lead before it
     * starts dropping frames outright. A one-bay sketch acknowledges each
     * downlink with ">>> SLOT AVAILABLE", so once the reserved lamp is wired up
     * the rig provokes a "reject" every time it speaks to its own board -- the
     * counter climbs forever and stops meaning anything.
     *
     * So the lines the rig causes, or that the board prints once at boot, are
     * recognised rather than counted. The list is exhaustive and exact on
     * purpose: anything outside it is still a reject, because the whole point
     * of the counter is to notice output nobody predicted.
     */
    function parking_sensor_is_board_chatter(string $line): bool
    {
        $line = trim($line);

        if ($line === '') {
            return false;
        }

        // The acknowledgement the sketch prints when it acts on R or A.
        if (strncmp($line, '>>>', 3) === 0) {
            return true;
        }

        // Our own helper's idle sentinel, emitted every second a quiet port
        // goes unread so the bridge's blocking read returns and its watchdog
        // can tick. It is deliberately unparseable, but charging it to the
        // board made a silent minute cost sixty rejects and sixty log lines,
        // and buried the reason the board went quiet. Matched exactly, so a
        // real frame from a board that calls itself LOCAL is still read.
        if ($line === 'SP1|LOCAL|0|IDLE||00') {
            return true;
        }

        // The boot banner, printed once per reset.
        if (preg_match('/^=+$/', $line) === 1) {
            return true;
        }

        if (preg_match('/^(SNDRA PARK SENSOR TEST|Commands:)$/i', $line) === 1) {
            return true;
        }

        // The help text listing the downlink commands.
        return preg_match('/^[RA] = (RESERVED|AVAILABLE)$/i', $line) === 1;
    }
}
