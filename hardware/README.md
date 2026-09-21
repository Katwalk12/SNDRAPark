# SNDRA Park slot-sensor rig

An Arduino watches each parking bay and reports occupancy over USB. A PHP bridge
on the XAMPP machine writes those readings into the database, and the existing
driver dashboard picks them up on its normal three-second poll. An LCD at the
entrance shows the free count per floor, driven back down the same cable.

Written for whoever builds and maintains the hardware.

## What decides a bay's status

Highest priority first:

1. **Inactive** — an admin closed the floor or the bay.
2. **Booth check-in** — a teller scanned a driver in. Billing owns the bay, so a
   sensor that has not noticed the car yet can never contradict it.
3. **Fresh sensor reading of occupied** — a vehicle is physically there. This
   beats a reservation hold on purpose: the next driver needs the truth,
   whoever booked it.
4. **Reservation hold** — somebody booked it but has not arrived.
5. **Admin manual override**, then **Available**.

A reading older than `SENSOR_STALE_SECONDS` counts for nothing. If the bridge
dies or the board is unplugged, every bay falls back to reservation data, which
is exactly how the system worked before the rig existed. The driver dashboard
shows a banner on any floor where that has happened.

**Escape hatch:** if a sensor sticks (a dirty lens reads as a permanent car),
disable it in the admin Slots page. Its bay returns to reservation data at once.

## Parts

| Part | Notes |
|---|---|
| Arduino Uno or Mega 2560 | Uno drives 12 bays, Mega 20. One sensor per bay. |
| IR obstacle sensors, one per bay | Ultrasonic works too; set `SENSOR_KIND`. |
| 16x2 I2C LCD (PCF8574 backpack) | Default address `0x27`. Some boards are `0x3F`. |
| USB cable, and a powered hub for a large rig | |

## Wiring

| Signal | Uno | Mega 2560 |
|---|---|---|
| Sensor outputs | D2 to D13 | D22 to D41 |
| LCD SDA | A4 | D20 |
| LCD SCL | A5 | D21 |
| Sensor VCC / GND | 5V / GND | 5V / GND |

**Never use D0 or D1.** They are the USB serial link, and a sensor on either one
breaks the uplink. Do not use the two I2C pins for sensors either, or the
display stops working.

IR modules usually pull their output LOW when something is in front of them. The
sketch assumes that. If yours goes HIGH on detection, set `ACTIVE_LOW` to `0`.

## Flashing

1. Open `sndra-slot-sensors/sndra-slot-sensors.ino` in the Arduino IDE.
2. Install the **LiquidCrystal_I2C** library through Library Manager.
3. Set `DEVICE_ID` to something short and unique, for example `UNO-A`. It must
   match what you type when mapping pins in the admin page. Letters, digits,
   hyphens and underscores only.
4. Select your board and port, then upload.

On boot the board announces itself, then sends every pin once a second forever.

## COM port numbering

The default `proc` mode handles any port number, COM12 and above included,
because .NET addresses a port by name.

The `com` mode does not. It opens the port as `COM3:`, a DOS device alias that
only resolves for COM1 through COM9; anything higher needs the UNC device
form, which PHP's Windows file wrapper will not open. If you must use `com`
mode, renumber the port in Device Manager under Port Settings then Advanced.

**Close the Arduino IDE Serial Monitor before starting the bridge.** Windows COM
ports are exclusive: whichever program opens the port first keeps it and the
other is told access is denied. This is the most common setup problem with this
rig, ahead of anything electrical.

## Mapping pins to bays

The board never knows a slot code. It reports `device_id` plus `sensor_pin`, and
the database holds the mapping, so re-assigning a bay is an admin edit rather
than a reflash and renaming a slot cannot silently break the rig.

Sign in as an admin, open **Manage Floors & Slots**, scroll to **Arduino sensor
rig**, and map each pin to a slot id. Slot ids are shown in the table and in the
slot gallery.

The bridge never creates a mapping by itself. A reading for an unmapped pin is
counted against the board and discarded, which is what stops a mis-flashed board
from inventing bays. The panel tells you which pins are reporting unmapped.

## Running the bridge

Set the port in `.env`:

```
SENSOR_BRIDGE_SOURCE=proc
SENSOR_SERIAL_PORT=COM3
SENSOR_SERIAL_BAUD=115200
SENSOR_STALE_SECONDS=120
```

Then register it to start at logon and restart on failure:

```
tools\register-serial-bridge-task.bat     (right-click, Run as administrator)
```

Or run it in a terminal while you are testing:

```
C:\xampp\php\php.exe backend\cli\serial-bridge.php
```

### Why `proc` is the default

PHP here has no `dio`, `com_dotnet`, `sockets` or `pcntl` extension, and
`stream_select()` on Windows only works on sockets. So PHP cannot read a COM
port with a real timeout: `fgets()` on an unplugged board blocks forever with
the process alive and silent.

In `proc` mode `tools/serial-pump.ps1` owns the port and relays lines on stdout.
That gives a real read timeout, a detectable unplug, and a deliberate wait for
the Uno's auto-reset instead of two mystery seconds of garbage.

`com` mode is two lines of PHP and fine for a bench test, but: COM1-COM9 only;
an unplug hangs the read forever; `mode ... dtr=on` resets the board so the
first 2.5 seconds are bootloader noise; and `feof()` is unreliable on these
handles.

**If both modes prove flaky**, the known answer is to have the PowerShell helper
open a `TcpListener` on loopback as well as the serial port, and connect from
PHP with `stream_socket_client()`. That is the only configuration in which PHP
on Windows gets genuine `stream_select()`, real read timeouts and working
`feof()`. It costs one loopback port.

## Using a board you already flashed

You do not have to reflash a working sketch. If yours prints a human-readable
line rather than a framed one, for example:

```
Distance: 175.92 cm | AVAILABLE
Distance: 12.40 cm | OCCUPIED
```

the bridge reads it directly. Tell it which bay that board is watching, because
a plain line carries no identity of its own:

```
SENSOR_DEVICE_ID=UNO-A
SENSOR_DEVICE_PIN=2
SENSOR_OCCUPIED_BELOW_CM=100
```

Then map that same device and pin to the bay in the admin Slots page, exactly as
you would for a board running the bundled sketch. The device id and pin are just
labels here; they only have to match between the two places.

The board's own status word wins whenever it prints one, because the sketch has
already applied its threshold and knows its mounting better than the server
does. `SENSOR_OCCUPIED_BELOW_CM` is only consulted for a line with a distance
and no verdict.

An HC-SR04 that hears no echo reports a sentinel rather than a reading, 999 in
the stock sketch. That is treated as a clear bay, never as a car parked 999 cm
away.

This adapter stays switched off unless both the device and the pin are set, so
an unframed line can never land on a bay that was merely guessed at.

**One board per bridge.** A plain-text board reports one bay, because the line
says nothing about which sensor it came from. Watching several bays means either
the bundled sketch, which names each pin, or one bridge per board.

## A one-bay board with a reserved lamp

`backend/iot/sensor.ino` is the other shape this rig comes in: one ultrasonic
sensor, three LEDs, one bay. It prints the plain line above, so the uplink works
the moment you point the bridge at it, and it takes a single character back the
other way to drive its amber lamp.

Wiring matches the sketch's own `#define` block:

| Signal | Uno pin |
|---|---|
| HC-SR04 TRIG | D9 |
| HC-SR04 ECHO | D10 |
| Green LED (available) | D4 |
| Yellow LED (reserved) | D5 |
| Red LED (occupied) | D6 |

**The board owns occupancy; the server owns the reservation.** The sketch pings
and lights red on its own, without waiting to be told, because it sees a car
arrive long before a frame could reach it. The only thing it cannot know is
whether somebody booked the bay, so that is the only thing sent down:

```
R    somebody holds this bay, or an admin took it out of service
A    it is free
```

An out-of-service bay shows amber rather than green on purpose. Three LEDs
cannot say "closed", and green invites a driver into a space nothing will bill
or track.

Set it up:

```
SENSOR_SERIAL_PORT=COM3
SENSOR_SERIAL_BAUD=9600        # the sketch calls Serial.begin(9600)
SENSOR_DEVICE_ID=UNO-A
SENSOR_DEVICE_PIN=9
SENSOR_OCCUPIED_BELOW_CM=45    # between the sketch's own 40 and 50
SENSOR_DOWNLINK=slot
```

`SENSOR_DEVICE_PIN` is only a label here — this board watches one bay and says
nothing about pins — but it has to match the pin you map in the admin page. D9
is the convention because that is where TRIG sits.

**`SENSOR_DOWNLINK=slot` is not optional for this board.** Left on `lcd` it
would be sent the entrance-sign frame, and the sketch reads one character per
loop and acts on any `R` or `A` it finds: "SNDRA Park" alone contains both, so
the amber lamp would flicker every two seconds. The two downlink shapes are
mutually hostile, which is why the mode is declared rather than guessed.

Check what the bay is about to be told without touching the hardware:

```
php backend\cli\serial-bridge.php --print-lcd --downlink=slot --device=UNO-A --pin=9
```

It prints the character and the status behind it, or tells you the pin is not
mapped yet. The baud rate, the threshold and the mode can all be passed as
flags too, which is usually quicker than editing `.env` while you are on a
ladder.

## Testing without hardware

The whole feature can be driven by hand:

```
# put a car in pin 2, clear pin 3
php backend\cli\serial-bridge.php --frame="SIM-1|2=1,3=0" --json

# what the LCD would show right now
php backend\cli\serial-bridge.php --print-lcd

# rehearse a dead bridge: age every reading past the staleness limit
php backend\cli\serial-bridge.php --age-readings=300
```

Map `SIM-1` pins to real slots in the admin page first, the same way you would
a real board.

## Wire protocol

ASCII, newline-terminated, `|` delimited, 200 bytes maximum. The `SP1` prefix
and the XOR-8 checksum exist to throw away the half-line you always get on the
first read after opening a port, and anything truncated by a board reset.

Board to PC:

```
SP1|UNO-A|0|HELLO|fw=1.0;pins=2-13;kind=IR|3C
SP1|UNO-A|1|F|2=0,3=1,4=0|7A          every second, every mapped pin
SP1|UNO-A|2|S|3=0|41                  the instant a bay changes
```

PC to board, `SENSOR_DOWNLINK=lcd`:

```
SP1|LCD|LG 12  1F 08 |2F 20  3F 03 |1E
```

Both LCD lines arrive padded to exactly sixteen characters, so the sketch does
no formatting. There is no acknowledgement; the frame is idempotent and resent
every two seconds.

PC to board, `SENSOR_DOWNLINK=slot`:

```
R
```

One unframed character for a one-bay board's reserved lamp. Unframed because
the sketch compares a single byte, and wrapping it would hide an `R` and an `A`
inside a checksum line the board would act on. Also idempotent, also resent
every two seconds, so a board that was power-cycled recovers its lamp without
waiting for the next booking.

The full frame at 1 Hz is the primary channel and is sufficient on its own.
Every frame is complete, so the PC side is stateless and self-healing and a
dropped frame costs one second. Nothing on either side may depend on an `S`
edge arriving.

The parser, checksum and debounce all have tests in `tests/run.php`, against
`backend/iot/common.php`. The sketch has no test harness, so that file is the
executable spec: change one, change the other.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Admin panel says no board has reported | Bridge not running, or wrong `SENSOR_SERIAL_PORT`. |
| Board reports but no bay changes | Pins are not mapped. The panel lists unmapped pins. |
| Every bay reads occupied on power-up | `ACTIVE_LOW` is inverted for your sensor modules. |
| A bay is stuck occupied | Dirty or misaligned sensor. The panel flags anything unchanged for a day. Disable it to free the bay. |
| LCD is blank | Wrong I2C address. Try `0x3F` in the sketch. |
| LCD says "Slot data stale" | No downlink for fifteen seconds. The bridge stopped. |
| Amber lamp flickers every couple of seconds | A one-bay board is being sent LCD frames. Set `SENSOR_DOWNLINK=slot`. |
| Amber lamp never lights | Pin not mapped, or `SENSOR_DOWNLINK` is not `slot`. `--print-lcd` says which. |
| One-bay board reports nothing | `SENSOR_SERIAL_BAUD` is 115200; that sketch speaks 9600. |
| Bridge says another is already running | A stale lock at `storage/cache/serial-bridge.lock`. |
| Port will not open | Port is above COM9, or something else holds it (close the Arduino IDE Serial Monitor). |
