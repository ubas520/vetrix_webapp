// Arduino UNO R4: USB Serial at 115200; SIM900A on Serial1 at 9600.
// Keep the same Serial1 wiring and suitable module power as your existing sketch.
// This checks hardware only. SMS delivery remains through SMSGate.
// No operator selection, radio resets, or SMS sending commands are issued.

char responseLine[96];
size_t responseLength = 0;
bool waiting = false;
bool sawCsq = false;
bool overflowed = false;
unsigned long startedAt = 0;
unsigned long nextPollAt = 0;

void setup() {
  Serial.begin(115200);
  Serial1.begin(9600);
  // Do not wait for a PC connection: polling must survive USB reconnections.
  nextPollAt = millis() + 5000UL;
}

void processLine() {
  responseLine[responseLength] = '\0';
  if (!waiting || overflowed) return;
  int strength = -1;
  int errorRate = -1;
  if (sscanf(responseLine, "+CSQ: %d,%d", &strength, &errorRate) == 2
      && ((strength >= 0 && strength <= 31) || strength == 99)
      && ((errorRate >= 0 && errorRate <= 7) || errorRate == 99)) {
    // Save evidence but only publish after this command also returns OK.
    sawCsq = true;
  } else if (strcmp(responseLine, "OK") == 0 && sawCsq) {
    if (Serial) Serial.println("VETRIX_GSM:RESPONDING");
    waiting = false;
  } else if (strcmp(responseLine, "ERROR") == 0 || strncmp(responseLine, "+CME ERROR:", 11) == 0) {
    waiting = false;
    if (Serial) Serial.println("VETRIX_GSM:NO_RESPONSE");
  }
}

void loop() {
  // Bound each pass so unexpected modem output cannot starve timeout handling.
  for (unsigned int count = 0; count < 256 && Serial1.available(); ++count) {
    char c = (char)Serial1.read();
    if (c == '\r') continue;
    if (c == '\n') {
      processLine();
      responseLength = 0;
      overflowed = false;
    } else if (responseLength < sizeof(responseLine) - 1) {
      responseLine[responseLength++] = c;
    } else {
      overflowed = true;
    }
  }
  unsigned long now = millis();
  if (waiting && now - startedAt >= 2000UL) {
    waiting = false;
    if (Serial) Serial.println("VETRIX_GSM:NO_RESPONSE");
  }
  if (!waiting && (long)(now - nextPollAt) >= 0) {
    responseLength = 0;
    overflowed = false;
    sawCsq = false;
    waiting = true;
    startedAt = now;
    nextPollAt = now + 3000UL;
    Serial1.print("AT+CSQ\r\n");
  }
}
