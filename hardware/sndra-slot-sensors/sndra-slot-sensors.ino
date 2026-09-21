/*
 * SNDRA Park -- slot occupancy sensors.
 *
 * One sensor per bay. The board reports which pins see a vehicle; the PC side
 * decides which bay each pin means. That split is deliberate: the mapping lives
 * in the database, so re-assigning a bay is an admin edit rather than a reflash,
 * and renaming a slot can never silently break the rig.
 *
 * Uplink, once a second, every mapped pin every time:
 *   SP1|<device>|<seq>|F|2=0,3=1|<crc>
 * Plus SP1|<device>|<seq>|HELLO|fw=1.0;pins=2-13;kind=IR|<crc> on boot, and
 * SP1|<device>|<seq>|S|3=1|<crc> the instant a bay changes.
 *
 * Downlink, whenever the counts change:
 *   SP1|LCD|<16 chars>|<16 chars>|<crc>
 * Both lines arrive already padded to exactly 16 characters, so this sketch
 * does no formatting at all -- it just setCursor and print.
 *
 * The full frame is the primary channel and is sufficient on its own. Every
 * frame is complete, so the PC side is stateless and a dropped frame costs one
 * second. Nothing here or there may depend on an S edge arriving.
 *
 * Protocol twin, and the thing that actually has tests:
 *   backend/iot/common.php   (parsing, checksum, debounce)
 * Change one, change the other.
 */

#include <Wire.h>
#include <LiquidCrystal_I2C.h>

/* ----------------------------------------------------------------- config -- */

static const char DEVICE_ID[] = "UNO-A";   // must match the admin mapping
static const char FIRMWARE[]  = "1.0";
static const char SENSOR_KIND[] = "IR";

/* IR modules pull their output LOW when something is in front of them. Set this
 * to 0 for a sensor that goes HIGH on detection. */
static const uint8_t ACTIVE_LOW = 1;

static const unsigned long SAMPLE_INTERVAL_MS = 20;
static const unsigned long FRAME_INTERVAL_MS  = 1000;
static const unsigned long LCD_INTERVAL_MS    = 2000;
static const unsigned long LCD_STALE_MS       = 15000;

/* Five agreeing samples at 20 ms, so 100 ms to confirm. Long enough to ignore a
 * noisy beam, short enough that a driver never beats the board to the bay. */
static const uint8_t DEBOUNCE_SAMPLES = 5;

#if defined(ARDUINO_AVR_MEGA2560)
static const uint8_t SENSOR_PINS[] = {
  22, 23, 24, 25, 26, 27, 28, 29, 30, 31,
  32, 33, 34, 35, 36, 37, 38, 39, 40, 41
};
#else
/* Uno: pins 0 and 1 are the USB serial link and A4/A5 are the I2C bus the LCD
 * sits on. Using any of those four breaks either the uplink or the display. */
static const uint8_t SENSOR_PINS[] = { 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13 };
#endif

static const uint8_t SENSOR_COUNT = sizeof(SENSOR_PINS) / sizeof(SENSOR_PINS[0]);

/* ------------------------------------------------------------------ state -- */

/* Three parallel arrays rather than an array of structs: an Uno has 2 KB of
 * SRAM and this keeps the per-sensor cost to three bytes. */
static uint8_t stableState[SENSOR_COUNT];
static uint8_t candidateState[SENSOR_COUNT];
static uint8_t candidateCount[SENSOR_COUNT];

static uint16_t sequence = 0;
static unsigned long lastSampleAt = 0;
static unsigned long lastFrameAt = 0;
static unsigned long lastLcdDrawAt = 0;
static unsigned long lastDownlinkAt = 0;

static char lineBuffer[96];
static uint8_t lineLength = 0;

static char lcdLineOne[17] = "SNDRA Park      ";
static char lcdLineTwo[17] = "Linking...      ";
static char shownLineOne[17] = "";
static char shownLineTwo[17] = "";
static bool lcdStaleShown = false;

LiquidCrystal_I2C lcd(0x27, 16, 2);

/* ------------------------------------------------------------- protocol --- */

static uint8_t frameChecksum(const char *body) {
  uint8_t checksum = 0;
  for (const char *cursor = body; *cursor != '\0'; cursor++) {
    checksum ^= (uint8_t)*cursor;
  }
  return checksum;
}

/*
 * Send one frame, or drop it if the outbound buffer cannot take it.
 *
 * Dropping is the right call and the guard is not optional. If the PC stops
 * reading, Windows buffers, our TX buffer fills, and Serial.write() blocks --
 * which would freeze sampling and the display along with it. A dropped frame
 * costs nothing, because the next one is complete.
 */
static void sendFrame(const char *type, const char *payload) {
  char body[176];
  snprintf(body, sizeof(body), "%s|%u|%s|%s", DEVICE_ID, sequence, type, payload);

  char frame[200];
  snprintf(frame, sizeof(frame), "SP1|%s|%02X", body, frameChecksum(body));

  size_t length = strlen(frame) + 1;
  if ((size_t)Serial.availableForWrite() < length) {
    return;
  }

  Serial.println(frame);
  sequence++;
}

static void sendFullFrame() {
  char payload[160];
  uint8_t offset = 0;
  payload[0] = '\0';

  for (uint8_t index = 0; index < SENSOR_COUNT; index++) {
    int written = snprintf(
      payload + offset,
      sizeof(payload) - offset,
      (index == 0 ? "%u=%u" : ",%u=%u"),
      SENSOR_PINS[index],
      stableState[index]
    );

    if (written <= 0 || (uint8_t)(offset + written) >= sizeof(payload)) {
      break;
    }

    offset += (uint8_t)written;
  }

  sendFrame("F", payload);
}

static void sendEdge(uint8_t index) {
  char payload[12];
  snprintf(payload, sizeof(payload), "%u=%u", SENSOR_PINS[index], stableState[index]);
  sendFrame("S", payload);
}

static void sendHello() {
  char payload[64];
  snprintf(
    payload,
    sizeof(payload),
    "fw=%s;pins=%u-%u;kind=%s",
    FIRMWARE,
    SENSOR_PINS[0],
    SENSOR_PINS[SENSOR_COUNT - 1],
    SENSOR_KIND
  );
  sendFrame("HELLO", payload);
}

/* -------------------------------------------------------------- sampling -- */

/*
 * One debounce tick per sensor. Mirrors parking_sensor_debounce_step() in
 * backend/iot/common.php, which is where this algorithm is actually tested --
 * a single dissenting sample restarts the run, so beam noise cannot walk a bay
 * from clear to occupied one sample at a time.
 */
static void sampleSensors() {
  for (uint8_t index = 0; index < SENSOR_COUNT; index++) {
    uint8_t raw = digitalRead(SENSOR_PINS[index]) == HIGH ? 1 : 0;
    uint8_t sample = ACTIVE_LOW ? (raw ? 0 : 1) : raw;

    if (sample == stableState[index]) {
      candidateState[index] = stableState[index];
      candidateCount[index] = 0;
      continue;
    }

    if (sample == candidateState[index] && candidateCount[index] > 0) {
      candidateCount[index]++;

      if (candidateCount[index] >= DEBOUNCE_SAMPLES) {
        stableState[index] = sample;
        candidateCount[index] = 0;
        sendEdge(index);
      }

      continue;
    }

    candidateState[index] = sample;
    candidateCount[index] = 1;
  }
}

/* -------------------------------------------------------------- downlink -- */

static void applyDownlink(const char *line) {
  /* SP1|LCD|<16>|<16>|<crc> -- verify before trusting it, because a garbled
   * line would otherwise put nonsense counts on a sign drivers act on. */
  if (strncmp(line, "SP1|LCD|", 8) != 0) {
    return;
  }

  const char *body = line + 4;
  const char *checksumSeparator = strrchr(line, '|');

  if (checksumSeparator == NULL || strlen(checksumSeparator + 1) != 2) {
    return;
  }

  char bodyCopy[64];
  size_t bodyLength = (size_t)(checksumSeparator - body);

  if (bodyLength >= sizeof(bodyCopy)) {
    return;
  }

  memcpy(bodyCopy, body, bodyLength);
  bodyCopy[bodyLength] = '\0';

  char expected[3];
  snprintf(expected, sizeof(expected), "%02X", frameChecksum(bodyCopy));

  if (strncasecmp(expected, checksumSeparator + 1, 2) != 0) {
    return;
  }

  /* bodyCopy is "LCD|<16>|<16>" and both lines are already padded by the PC. */
  char *firstSeparator = strchr(bodyCopy, '|');
  if (firstSeparator == NULL) {
    return;
  }

  char *secondSeparator = strchr(firstSeparator + 1, '|');
  if (secondSeparator == NULL) {
    return;
  }

  *secondSeparator = '\0';
  strncpy(lcdLineOne, firstSeparator + 1, 16);
  lcdLineOne[16] = '\0';
  strncpy(lcdLineTwo, secondSeparator + 1, 16);
  lcdLineTwo[16] = '\0';

  lastDownlinkAt = millis();
  lcdStaleShown = false;
}

static void readDownlink() {
  while (Serial.available() > 0) {
    char incoming = (char)Serial.read();

    if (incoming == '\n' || incoming == '\r') {
      if (lineLength > 0) {
        lineBuffer[lineLength] = '\0';
        applyDownlink(lineBuffer);
        lineLength = 0;
      }
      continue;
    }

    if (lineLength >= sizeof(lineBuffer) - 1) {
      lineLength = 0;
      sendFrame("ERR", "overrun");
      continue;
    }

    lineBuffer[lineLength++] = incoming;
  }
}

/* ------------------------------------------------------------------- lcd -- */

static void drawLcd() {
  /* Only redraw on change. An unconditional refresh at this cadence makes an
   * HD44780 visibly flicker. */
  if (strcmp(shownLineOne, lcdLineOne) == 0 && strcmp(shownLineTwo, lcdLineTwo) == 0) {
    return;
  }

  lcd.setCursor(0, 0);
  lcd.print(lcdLineOne);
  lcd.setCursor(0, 1);
  lcd.print(lcdLineTwo);

  strncpy(shownLineOne, lcdLineOne, sizeof(shownLineOne));
  strncpy(shownLineTwo, lcdLineTwo, sizeof(shownLineTwo));
}

/*
 * Say so when the counts have gone stale.
 *
 * A sign showing confidently wrong free-slot numbers is worse than a sign
 * admitting it has lost touch, so after fifteen quiet seconds it says so.
 */
static void markLcdStaleIfSilent() {
  if (lastDownlinkAt == 0 || lcdStaleShown) {
    return;
  }

  if (millis() - lastDownlinkAt < LCD_STALE_MS) {
    return;
  }

  strncpy(lcdLineTwo, "Slot data stale ", sizeof(lcdLineTwo));
  lcdLineTwo[16] = '\0';
  lcdStaleShown = true;
}

/* ----------------------------------------------------------------- setup -- */

void setup() {
  Serial.begin(115200);

  for (uint8_t index = 0; index < SENSOR_COUNT; index++) {
    pinMode(SENSOR_PINS[index], ACTIVE_LOW ? INPUT_PULLUP : INPUT);
    stableState[index] = 0;
    candidateState[index] = 0;
    candidateCount[index] = 0;
  }

  lcd.init();
  lcd.backlight();
  drawLcd();

  /* Let the inputs settle before the first frame, or the board reports a row of
   * phantom vehicles the moment it powers up. */
  delay(250);

  for (uint8_t settle = 0; settle < DEBOUNCE_SAMPLES; settle++) {
    sampleSensors();
    delay(SAMPLE_INTERVAL_MS);
  }

  sendHello();
  sendFullFrame();
}

void loop() {
  unsigned long now = millis();

  /* Subtraction on unsigned long, never `now > last + interval`: the latter
   * stops working when millis() wraps at about 49 days and the rig would go
   * silent until someone power-cycled it. */
  if (now - lastSampleAt >= SAMPLE_INTERVAL_MS) {
    lastSampleAt = now;
    sampleSensors();
  }

  if (now - lastFrameAt >= FRAME_INTERVAL_MS) {
    lastFrameAt = now;
    sendFullFrame();
  }

  readDownlink();
  markLcdStaleIfSilent();

  if (now - lastLcdDrawAt >= LCD_INTERVAL_MS) {
    lastLcdDrawAt = now;
    drawLcd();
  }
}
