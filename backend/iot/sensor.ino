#define TRIG_PIN 9
#define ECHO_PIN 10

#define GREEN_LED 4
#define YELLOW_LED 5
#define RED_LED 6

#define OCCUPIED_DISTANCE 40
#define CLEAR_DISTANCE 50

bool reserved = false;
bool vehicleDetected = false;

void setup() {

  Serial.begin(9600);

  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);

  pinMode(GREEN_LED, OUTPUT);
  pinMode(YELLOW_LED, OUTPUT);
  pinMode(RED_LED, OUTPUT);

  digitalWrite(TRIG_PIN, LOW);

  setAvailable();

  Serial.println("================================");
  Serial.println("SNDRA PARK SENSOR TEST");
  Serial.println("================================");
  Serial.println("Commands:");
  Serial.println("R = RESERVED");
  Serial.println("A = AVAILABLE");
  Serial.println("================================");
}

void loop() {

  // Check Serial Monitor command
  checkSerial();

  // Measure distance
  float distance = getDistance();

  // Determine if vehicle/object is detected
  if (distance <= OCCUPIED_DISTANCE) {
    vehicleDetected = true;
  }
  else if (distance >= CLEAR_DISTANCE) {
    vehicleDetected = false;
  }

  // LED logic
  updateLED();

  // Display sensor information
  Serial.print("Distance: ");
  Serial.print(distance);
  Serial.print(" cm | ");

  if (vehicleDetected) {
    Serial.println("OCCUPIED");
  }
  else if (reserved) {
    Serial.println("RESERVED");
  }
  else {
    Serial.println("AVAILABLE");
  }

  delay(500);
}


// ========================================
// HC-SR04 SENSOR
// ========================================

float getDistance() {

  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);

  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);

  digitalWrite(TRIG_PIN, LOW);

  long duration = pulseIn(ECHO_PIN, HIGH, 30000);

  if (duration == 0) {
    return 999;
  }

  float distance = duration * 0.0343 / 2;

  return distance;
}


// ========================================
// LED LOGIC
// ========================================

void updateLED() {

  // Vehicle always has highest priority
  if (vehicleDetected) {

    setOccupied();

  }

  // No vehicle + reservation
  else if (reserved) {

    setReserved();

  }

  // No vehicle + no reservation
  else {

    setAvailable();

  }
}


// ========================================
// AVAILABLE
// ========================================

void setAvailable() {

  digitalWrite(GREEN_LED, HIGH);
  digitalWrite(YELLOW_LED, LOW);
  digitalWrite(RED_LED, LOW);
}


// ========================================
// RESERVED
// ========================================

void setReserved() {

  digitalWrite(GREEN_LED, LOW);
  digitalWrite(YELLOW_LED, HIGH);
  digitalWrite(RED_LED, LOW);
}


// ========================================
// OCCUPIED
// ========================================

void setOccupied() {

  digitalWrite(GREEN_LED, LOW);
  digitalWrite(YELLOW_LED, LOW);
  digitalWrite(RED_LED, HIGH);
}


// ========================================
// SERIAL COMMAND
// ========================================

void checkSerial() {

  if (Serial.available() > 0) {

    char command = Serial.read();

    // R = Reserved
    if (command == 'R' || command == 'r') {

      reserved = true;

      Serial.println(">>> SLOT RESERVED");

    }

    // A = Available
    else if (command == 'A' || command == 'a') {

      reserved = false;

      Serial.println(">>> SLOT AVAILABLE");

    }
  }
}