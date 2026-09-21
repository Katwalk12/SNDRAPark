<?php

declare(strict_types=1);

/**
 * Serial bridge for the Arduino slot-sensor rig.
 *
 * The board has no network, so it prints occupancy over USB and this process
 * relays it into the database. It also renders the free-slot counts back down
 * the same link for the entrance LCD.
 *
 * Run it from Task Scheduler with restart-on-failure:
 *
 *   C:\xampp\php\php.exe C:\xampp\htdocs\sndraPark\backend\cli\serial-bridge.php
 *
 * tools/register-serial-bridge-task.bat registers exactly that.
 *
 * Sources:
 *   proc   a PowerShell helper owns the COM port and relays lines on stdout.
 *          The default, because it is the only mode with a real read timeout
 *          and a detectable unplug. See tools/serial-pump.ps1.
 *   com    fopen('COM3:') straight from PHP. Two lines, fine on a bench, but
 *          COM1-COM9 only and an unplug leaves the read blocked forever.
 *   stdin  read frames from standard input. For piping captures.
 *   sim    no hardware at all; use with --frame to drive the app by hand.
 *
 * Flags:
 *   --source=proc|com|stdin|sim
 *   --port=COM3           --baud=115200
 *   --frame="UNO-A|2=1"   inject one frame, implies --source=sim
 *   --once                handle one frame and exit
 *   --print-lcd           print the two LCD lines that would be sent, and exit
 *   --age-readings=300    backdate every reading, to rehearse a dead bridge
 *   --quiet --json
 *
 * Plain-text boards:
 *   A sketch that prints "Distance: 42.1 cm | OCCUPIED" instead of a framed
 *   line is understood too, but only when you say which bay it is watching:
 *
 *     --device=UNO-A --pin=2 [--threshold=100]
 *
 *   --threshold is the centimetre distance below which a bay counts as taken,
 *   and is only consulted when the sketch prints no status word of its own.
 *
 * Downlink:
 *   --downlink=lcd    SP1|LCD|<16>|<16>|<crc> for the entrance sign. Default.
 *   --downlink=slot   a bare 'R' or 'A' for a one-bay board that lights a
 *                     reserved lamp. Needs --device and --pin.
 *   --downlink=none   say nothing back down the link.
 *
 *   A board gets one or the other, never both: a sketch that reads single-char
 *   commands finds an 'R' and an 'A' inside the LCD text and flaps its lamp.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../parking/common.php';
require_once __DIR__ . '/../iot/sensors.php';

$options = getopt('', [
    'source::',
    'port::',
    'baud::',
    'frame::',
    'once',
    'print-lcd',
    'age-readings::',
    'device::',
    'pin::',
    'threshold::',
    'downlink::',
    'quiet',
    'json'
]);

$quiet = array_key_exists('quiet', $options);
$asJson = array_key_exists('json', $options);
$injectedFrame = isset($options['frame']) ? (string) $options['frame'] : '';
$runOnce = array_key_exists('once', $options) || $injectedFrame !== '';

$source = (string) ($options['source'] ?? (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_BRIDGE_SOURCE', 'proc') : 'proc'));
if ($injectedFrame !== '') {
    $source = 'sim';
}

$port = (string) ($options['port'] ?? (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_SERIAL_PORT', 'COM3') : 'COM3'));
$baud = (int) ($options['baud'] ?? (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_SERIAL_BAUD', '115200') : '115200'));

/**
 * Identity for a board that prints for people rather than in frames.
 *
 * Only set when --device and --pin are both given. Left empty, the plain-text
 * adapter stays switched off entirely, so an unframed line is still a reject
 * and no reading can ever land on a bay we merely guessed at.
 */
$GLOBALS['__bridge_plain'] = [
    'device' => parking_sensor_normalize_device_id(
        (string) ($options['device'] ?? (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_DEVICE_ID', '') : ''))
    ),
    'pin' => isset($options['pin'])
        ? (int) $options['pin']
        : (int) (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_DEVICE_PIN', '-1') : '-1'),
    'threshold' => (float) ($options['threshold'] ?? (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_OCCUPIED_BELOW_CM', '100') : '100'))
];

/**
 * Which shape of downlink this board understands.
 *
 * Explicit rather than inferred from the traffic. The two forms are mutually
 * hostile -- the LCD text contains the very characters a single-char sketch
 * treats as commands -- so guessing wrong is not a degraded display, it is a
 * lamp flickering between reserved and available once every two seconds.
 */
$downlinkMode = strtolower(trim((string) ($options['downlink']
    ?? (class_exists('EnvHelper') ? EnvHelper::get('SENSOR_DOWNLINK', 'lcd') : 'lcd'))));

if (!in_array($downlinkMode, ['lcd', 'slot', 'none'], true)) {
    exit("--downlink must be lcd, slot or none\n");
}

if ($downlinkMode === 'slot'
    && ($GLOBALS['__bridge_plain']['device'] === '' || $GLOBALS['__bridge_plain']['pin'] < 0)) {
    exit("--downlink=slot needs --device and --pin, so the reserved lamp lands on a mapped bay\n");
}

$GLOBALS['__bridge_downlink_mode'] = $downlinkMode;

$report = [
    'started_at' => date('c'),
    'source' => $source,
    'frames' => 0,
    'rejects' => 0,
    'edges' => 0,
    'mismatches' => 0,
    'errors' => []
];

function bridge_say(string $message): void
{
    global $quiet, $asJson;

    if ($quiet || $asJson) {
        return;
    }

    echo '[' . date('H:i:s') . '] ' . $message . "\n";
}

/**
 * Render the entrance sign and stage it for the board.
 *
 * Written through a temp file and renamed, so the PowerShell helper can never
 * read a half-written frame -- rename is atomic on NTFS, a plain overwrite is not.
 */
function bridge_publish_lcd(mysqli $connection): array
{
    $lines = parking_sensor_build_lcd_frame(parking_sensor_free_counts_by_floor($connection));
    $downlink = parking_sensor_build_lcd_downlink($lines[0], $lines[1]);

    $target = parking_runtime_cache_dir() . DIRECTORY_SEPARATOR . 'serial-downlink.txt';
    $temporary = $target . '.tmp';

    file_put_contents($temporary, $downlink . "\n");
    @rename($temporary, $target);

    return ['lines' => $lines, 'frame' => $downlink];
}

/**
 * Stage the reserved lamp for a one-bay board.
 *
 * Only the reservation travels down this link. The board decides occupancy
 * itself from its own ping and lights red without asking, which is the right
 * split -- it sees a car arrive long before a frame could tell it.
 *
 * An unmapped pin stages nothing. Telling a board 'A' for a bay no admin has
 * mapped would light a green lamp on a space the system does not track.
 */
function bridge_publish_slot_command(mysqli $connection): array
{
    $config = $GLOBALS['__bridge_plain'];
    $status = parking_sensor_slot_status_for_pin($connection, $config['device'], $config['pin']);

    if ($status === null) {
        return ['command' => '', 'status' => '', 'mapped' => false];
    }

    $command = parking_sensor_build_slot_downlink($status);

    // Rewrite only on a change, plus a slow heartbeat. The helper resends
    // whenever this file's timestamp moves, and the board answers every
    // downlink with a line of its own -- so restaging an unchanged 'A' every
    // couple of seconds would keep the link permanently busy with the rig
    // talking to itself. The heartbeat is what still recovers a board that was
    // power-cycled, just at a rate that costs nothing.
    static $lastCommand = '';
    static $lastWriteAt = 0;

    if ($command !== $lastCommand || time() - $lastWriteAt >= 30) {
        $target = parking_runtime_cache_dir() . DIRECTORY_SEPARATOR . 'serial-downlink.txt';
        $temporary = $target . '.tmp';

        file_put_contents($temporary, $command . "\n");
        @rename($temporary, $target);

        $lastCommand = $command;
        $lastWriteAt = time();
    }

    return ['command' => $command, 'status' => $status, 'mapped' => true];
}

/**
 * Send whatever this board's downlink mode calls for.
 *
 * Resent on a timer even when nothing moved, in either mode, so a board that
 * was power-cycled picks the current state back up within a couple of seconds
 * instead of sitting on a stale lamp until the next reservation.
 */
function bridge_publish_downlink(mysqli $connection): array
{
    $mode = (string) ($GLOBALS['__bridge_downlink_mode'] ?? 'lcd');

    if ($mode === 'none') {
        return ['mode' => 'none'];
    }

    if ($mode === 'slot') {
        return ['mode' => 'slot'] + bridge_publish_slot_command($connection);
    }

    return ['mode' => 'lcd'] + bridge_publish_lcd($connection);
}

/**
 * Handle one line from the board.
 */
function bridge_handle_line(mysqli $connection, string $line, string $transport, string $portLabel, array &$report): void
{
    // One bridge process owns one link, so the board that last spoke here is
    // the board a later garbled line came from.
    static $lastDeviceId = '';

    $line = trim($line);

    if ($line === '') {
        return;
    }

    $frame = parking_sensor_parse_line($line);

    if ($frame === null) {
        // Fall back to the human-readable form a stock sketch prints. A board
        // flashed before this integration existed is still a working sensor,
        // and reflashing it is a cost the bridge can absorb instead.
        $frame = bridge_adapt_plain_line($line);
    }

    if ($frame === null) {
        // Chatter the rig itself provoked, or the boot banner. Counting it
        // would bury the one signal the reject counter exists to give.
        if (parking_sensor_is_board_chatter($line)) {
            return;
        }

        $report['rejects']++;
        bridge_say('rejected: ' . substr($line, 0, 80));

        // A line that failed the checksum has no device id worth believing, so
        // it is charged to whichever board last spoke on this link. One bridge
        // owns one port, so that is the right board -- and a reject counter
        // that climbs is how you notice a loose USB lead before it drops
        // frames outright.
        if ($lastDeviceId !== '') {
            parking_sensor_count_reject($connection, $lastDeviceId);
        }

        return;
    }

    $lastDeviceId = $frame['device_id'];
    $report['frames']++;

    $firmware = null;
    if ($frame['type'] === 'HELLO') {
        $details = parking_sensor_parse_hello($frame['payload']);
        $firmware = $details['fw'] ?? null;
        bridge_say('hello from ' . $frame['device_id'] . ' (' . ($firmware ?? 'unknown firmware') . ')');
    }

    parking_sensor_touch_device($connection, $frame['device_id'], $frame['seq'], $transport, $portLabel, $firmware);

    if ($frame['readings'] === []) {
        return;
    }

    $applied = parking_sensor_apply_readings($connection, $frame['device_id'], $frame['readings']);
    $report['edges'] += $applied['edges'];

    if ($applied['unmapped'] !== []) {
        bridge_say('unmapped pins on ' . $frame['device_id'] . ': ' . implode(',', $applied['unmapped']));
    }

    if ($applied['edges'] < 1) {
        // A heartbeat with nothing moving. Forcing a status sync here would
        // rewrite every slot row once a second for no reason.
        return;
    }

    bridge_say($applied['edges'] . ' bay(s) changed on ' . $frame['device_id']);

    // Forced, so the five-second request-path throttle does not sit between a
    // car arriving and the dashboard showing it. A lock wait against a
    // concurrent Apache sync must not kill the read loop, so this is non-fatal:
    // the request path catches up within five seconds regardless.
    try {
        parking_sync_slot_statuses($connection, true);
        $report['mismatches'] += parking_sensor_sync_mismatches($connection);
    } catch (Throwable $syncException) {
        $report['errors'][] = 'sync: ' . $syncException->getMessage();
        bridge_say('sync deferred: ' . $syncException->getMessage());
    }

    bridge_publish_downlink($connection);
}

try {
    $connection = booth_db();

    // Never let a lock wait against the request path stall the read loop.
    $connection->query('SET SESSION innodb_lock_wait_timeout = 5');

    if (isset($options['age-readings'])) {
        $ageSeconds = max(0, (int) $options['age-readings']);
        $statement = $connection->prepare("
            UPDATE parking_slot_sensors
            SET last_reading_at = DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $statement->bind_param('i', $ageSeconds);
        $statement->execute();
        $statement->close();

        parking_sync_slot_statuses($connection, true);
        bridge_say('backdated every reading by ' . $ageSeconds . ' seconds');

        if ($asJson) {
            echo json_encode(array_merge($report, ['aged_by_seconds' => $ageSeconds])) . "\n";
        }

        exit(0);
    }

    if (array_key_exists('print-lcd', $options)) {
        // Named for the LCD, but what it is really for is "show me what this
        // board is about to be told", so it follows the mode rather than
        // printing a frame that would never be sent.
        $published = bridge_publish_downlink($connection);

        if ($asJson) {
            echo json_encode($published) . "\n";
        } elseif ($published['mode'] === 'slot') {
            echo $published['mapped']
                ? $published['command'] . '   (' . $published['status'] . ")\n"
                : "no mapping for that device and pin, nothing to send\n";
        } elseif ($published['mode'] === 'lcd') {
            echo '|' . $published['lines'][0] . "|\n";
            echo '|' . $published['lines'][1] . "|\n";
        } else {
            echo "downlink disabled\n";
        }

        exit(0);
    }

    if ($source === 'sim') {
        if ($injectedFrame === '') {
            exit("--source=sim needs a --frame, for example --frame=\"SIM-1|2=1,3=0\"\n");
        }

        // A hand-typed frame carries no sequence and no checksum, so build a
        // real one around it rather than making the parser lenient: the parser
        // is the thing protecting live bays from a garbled line.
        [$deviceId, $payload] = array_pad(explode('|', $injectedFrame, 2), 2, '');
        $deviceId = parking_sensor_normalize_device_id($deviceId);

        if ($deviceId === '') {
            exit("--frame must start with a device id, for example SIM-1|2=1\n");
        }

        bridge_handle_line(
            $connection,
            parking_sensor_build_frame($deviceId, 1, 'F', trim($payload)),
            'sim',
            'simulator',
            $report
        );
    } elseif ($source === 'stdin') {
        $handle = fopen('php://stdin', 'rb');

        while (($line = fgets($handle)) !== false) {
            bridge_handle_line($connection, $line, 'stdin', 'stdin', $report);

            if ($runOnce) {
                break;
            }
        }

        fclose($handle);
    } else {
        // A real port is exclusive on Windows, but two simulators are not, and
        // two bridges would fight over the same rows.
        $lockFile = parking_runtime_cache_dir() . DIRECTORY_SEPARATOR . 'serial-bridge.lock';
        $lock = fopen($lockFile, 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            exit("Another serial bridge is already running.\n");
        }

        ftruncate($lock, 0);
        fwrite($lock, (string) getmypid());
        fflush($lock);

        $backoffSeconds = 1;

        while (true) {
            $stream = bridge_open_source($source, $port, $baud, $report);

            if ($stream === null) {
                bridge_say('no link, retrying in ' . $backoffSeconds . 's');
                sleep($backoffSeconds);
                $backoffSeconds = min(30, $backoffSeconds * 2);
                continue;
            }

            $lastFrameAt = time();
            $lastLcdAt = 0;
            $linesRead = 0;

            while (($line = fgets($stream['read'])) !== false) {
                $linesRead++;
                $before = $report['frames'];
                bridge_handle_line($connection, $line, $source, $port, $report);

                if ($report['frames'] > $before) {
                    $lastFrameAt = time();
                }

                // Refresh even when nothing moved, so a board that was rebooted
                // picks the current state back up within a couple of seconds.
                if (time() - $lastLcdAt >= 2) {
                    bridge_publish_downlink($connection);
                    $lastLcdAt = time();
                }

                if ($source === 'com' && isset($stream['write'])) {
                    bridge_write_downlink($stream['write']);
                }

                if ($runOnce) {
                    break 2;
                }

                // Watchdog. In 'proc' mode the helper emits an idle line every
                // second, so silence here means the board stopped talking.
                if (time() - $lastFrameAt > 30) {
                    bridge_say('no frames for 30s, reconnecting');
                    break;
                }
            }

            $reason = bridge_drain_error($stream);
            bridge_close_source($stream);

            // A helper that started and then died without ever speaking never
            // had the port. Resetting the backoff before the inner loop, as
            // this used to, made that case a spin: proc_open succeeds, the
            // helper exits on a denied port, fgets returns false at once, and
            // the loop comes straight back round with no delay at all. Several
            // reconnects a second, and a log that buries the one line saying
            // why. The backoff now resets only once a link has proved itself.
            if ($linesRead > 0) {
                $backoffSeconds = 1;
                bridge_say('link closed, reconnecting');
                continue;
            }

            if ($reason !== '') {
                $report['errors'][] = $reason;
            }

            bridge_say(
                ($reason !== '' ? $reason : 'link died without a word')
                . ', retrying in ' . $backoffSeconds . 's'
            );

            sleep($backoffSeconds);
            $backoffSeconds = min(30, $backoffSeconds * 2);
        }
    }

    $report['finished_at'] = date('c');

    if ($asJson) {
        echo json_encode($report) . "\n";
    } elseif (!$quiet) {
        printf(
            "frames=%d rejects=%d edges=%d mismatches=%d\n",
            $report['frames'],
            $report['rejects'],
            $report['edges'],
            $report['mismatches']
        );
    }

    exit($report['errors'] === [] ? 0 : 1);
} catch (Throwable $exception) {
    $report['errors'][] = $exception->getMessage();

    if ($asJson) {
        echo json_encode($report) . "\n";
    } else {
        fwrite(STDERR, 'serial-bridge failed: ' . $exception->getMessage() . "\n");
    }

    exit(1);
}

/**
 * Open the chosen transport, or return null so the caller can back off.
 *
 * @return array{read:resource,write?:resource,process?:resource}|null
 */
function bridge_open_source(string $source, string $port, int $baud, array &$report): ?array
{
    if ($source === 'com') {
        // The bare COMn: form is a DOS device alias and only resolves for
        // COM1-COM9. Higher ports need \\.\COMnn, which PHP's Windows plain
        // files wrapper will not open -- so pin the board low in Device Manager.
        $mode = sprintf('mode %s: BAUD=%d PARITY=n DATA=8 STOP=1 xon=off octs=off rts=on dtr=on', $port, $baud);
        exec($mode . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $report['errors'][] = 'mode: ' . implode(' ', $output);
            return null;
        }

        // dtr=on above just reset the board. Everything it says for the next
        // couple of seconds is bootloader noise, and the parser will reject it.
        usleep(2500000);

        $handle = @fopen($port . ':', 'r+b');

        if ($handle === false) {
            $report['errors'][] = 'could not open ' . $port;
            return null;
        }

        return ['read' => $handle, 'write' => $handle];
    }

    $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'serial-pump.ps1';
    // Hand the helper our PID so it releases the port if we are killed. A COM
    // port is exclusive, and an orphaned pump makes every later bridge
    // unstartable until somebody finds and kills it by hand.
    $command = sprintf(
        'powershell -NoProfile -ExecutionPolicy Bypass -File "%s" -Port %s -Baud %d -ParentPid %d',
        $script,
        $port,
        $baud,
        getmypid()
    );

    $pipes = [];
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        $report['errors'][] = 'could not start serial-pump.ps1';
        return null;
    }

    // Non-blocking, because this is read on the way out of a dead link and a
    // helper that exited cleanly leaves nothing behind. A blocking read there
    // would hang the reconnect on the very failure it is trying to explain.
    stream_set_blocking($pipes[2], false);

    // proc_open runs a command string through cmd.exe, so this pid is the
    // wrapper's, not the helper's. Kept because killing the wrapper alone
    // leaves the helper holding the port -- see bridge_close_source.
    $status = proc_get_status($process);

    return [
        'read' => $pipes[1],
        'error' => $pipes[2],
        'process' => $process,
        'pid' => is_array($status) ? (int) $status['pid'] : 0
    ];
}

/**
 * Whatever the helper said on its way out, as one line.
 *
 * "Access to the port 'COM12' is denied" is the single most useful sentence
 * this rig ever produces -- it is the Arduino IDE Serial Monitor holding the
 * port, which is the most common setup problem there is. It was being written
 * to a pipe nobody read, so the bridge reported a link that closed and left you
 * to guess. Surfacing it costs one read.
 */
function bridge_drain_error(array $stream): string
{
    if (!isset($stream['error']) || !is_resource($stream['error'])) {
        return '';
    }

    $text = (string) stream_get_contents($stream['error']);

    foreach (explode("
", $text) as $line) {
        $line = trim($line);

        if ($line !== '') {
            return substr($line, 0, 200);
        }
    }

    return '';
}

function bridge_close_source(array $stream): void
{
    foreach (['read', 'error'] as $key) {
        if (isset($stream[$key]) && is_resource($stream[$key])) {
            fclose($stream[$key]);
        }
    }

    if (!isset($stream['process']) || !is_resource($stream['process'])) {
        return;
    }

    // Kill the tree, not the handle. proc_open ran the helper through cmd.exe,
    // so proc_terminate() reaches the wrapper and stops there -- and the
    // PowerShell grandchild goes on owning the COM port. Its own watchdog
    // cannot save us either: that watches the bridge, which is still very much
    // alive, so it sees nothing wrong. The result was one leaked helper per
    // reconnect, each still holding the port the next one needs, and a rig that
    // never recovered from a single quiet minute until the bridge was killed by
    // hand. /T is the whole fix.
    if (!empty($stream['pid'])) {
        exec(sprintf('taskkill /F /T /PID %d 2>&1', (int) $stream['pid']), $ignored, $ignoredCode);
    }

    proc_terminate($stream['process']);
    proc_close($stream['process']);
}

/**
 * Push the staged LCD frame straight down a COM handle.
 *
 * In 'proc' mode the PowerShell helper picks the same file up itself; this is
 * only for the direct-fopen path. The flush is not optional -- PHP buffers
 * writes to these handles and the display simply never updates without it.
 */
function bridge_write_downlink($handle): void
{
    static $lastSent = '';
    static $lastSentAt = 0;

    $file = parking_runtime_cache_dir() . DIRECTORY_SEPARATOR . 'serial-downlink.txt';

    if (!is_file($file)) {
        return;
    }

    $frame = trim((string) file_get_contents($file));

    // Unchanged frames still go out every ten seconds. A board that browned out
    // or was reset comes back with its lamp in whatever state the sketch boots
    // into, and without a resend it would sit there wrong until the next
    // reservation happened to change the frame. In 'proc' mode the helper gets
    // this for free, because the bridge rewrites the drop-file on a timer.
    if ($frame === '' || ($frame === $lastSent && time() - $lastSentAt < 10)) {
        return;
    }

    fwrite($handle, $frame . "\n");
    fflush($handle);
    $lastSent = $frame;
    $lastSentAt = time();
}

/**
 * Wrap a plain sketch line in the frame shape the rest of the bridge expects.
 *
 * Returns null unless --device and --pin were given, so this never fires by
 * accident: without an explicit identity a plain line could only be guessed at,
 * and guessing which bay a reading belongs to is exactly the mistake that lets
 * one board take over another board's slots.
 */
function bridge_adapt_plain_line(string $line): ?array
{
    static $sequence = 0;

    $config = $GLOBALS['__bridge_plain'] ?? null;

    if (!is_array($config) || $config['device'] === '' || $config['pin'] < 0) {
        return null;
    }

    $reading = parking_sensor_parse_plain_line($line, $config['threshold']);

    if ($reading === null) {
        return null;
    }

    return [
        'device_id' => $config['device'],
        'seq' => $sequence++ % 65536,
        'type' => 'F',
        'payload' => $config['pin'] . '=' . $reading['occupied'],
        'readings' => [$config['pin'] => $reading['occupied']],
        'distance_cm' => $reading['distance_cm']
    ];
}
