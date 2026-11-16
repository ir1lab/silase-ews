/*
 * ============================================================================
 *  SILASE-EWS v1.0
 *  Program Komputer Firmware Node Monitoring dan Peringatan Dini
 *  Fermentasi Pakan Berbasis ESP32
 *
 *  Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Pencipta : Irwan Novianto, S.T., M.Eng. [lengkapi pencipta lain]
 *
 *  Berkas   : main.cpp
 *  Fungsi   : Program utama. Menjadwalkan siklus pengukuran, menjalankan
 *             evaluasi peringatan dini, menyusun data JSON, mengirim atau
 *             menyimpan data, mengendalikan alarm lokal (buzzer dan LED),
 *             serta menerima perintah melalui Serial Monitor dan MQTT.
 *
 *  Alur satu siklus pengukuran:
 *    baca sensor -> validasi rentang -> hitung dT & evaluasi status ->
 *    kirim MQTT (atau simpan ke buffer bila offline) -> perbarui alarm
 *
 *  Perintah (Serial maupun topik silase-ews/<DEVICE_ID>/cmd):
 *    start_cycle : mulai siklus fermentasi baru (umur siklus & CO2 maks direset)
 *    sample_now  : lakukan pengukuran segera
 *    mute        : bisukan buzzer hingga status berubah
 * ============================================================================
 */
#include <Arduino.h>
#include <ArduinoJson.h>

#include "config.h"
#include "sensors.h"
#include "ews.h"
#include "comms.h"

static uint32_t lastSampleMs = 0;
static uint32_t elapsedAccMs = 0;
static bool sampleRequested = true;     // ukur segera setelah boot
static AlertLevel alarmLevel = LVL_NORMAL;
static bool muted = false;
static uint32_t seq = 0;

// ---------------------------------------------------------------- Perintah
static void handleCommand(const String &cmd) {
  if (cmd == "start_cycle") {
    ewsStartCycle();
    sampleRequested = true;
  } else if (cmd == "sample_now") {
    sampleRequested = true;
  } else if (cmd == "mute") {
    muted = true;
    Serial.println("[ALARM] buzzer dibisukan");
  } else {
    Serial.printf("[CMD] perintah tidak dikenal: %s\n", cmd.c_str());
  }
}

static void pollSerial() {
  if (!Serial.available()) return;
  String cmd = Serial.readStringUntil('\n');
  cmd.trim();
  if (cmd.length()) handleCommand(cmd);
}

// ---------------------------------------------------------------- Alarm lokal
// NORMAL : buzzer diam, LED berkedip singkat tiap 5 detik
// WASPADA: bip 200 ms tiap 10 detik, LED berkedip cepat
// BAHAYA : bip 500 ms on / 500 ms off terus-menerus, LED menyala
static void alarmUpdate() {
  uint32_t t = millis();
  bool buzz = false, led = false;
  switch (alarmLevel) {
    case LVL_BAHAYA:
      buzz = (t % 1000) < 500;
      led = true;
      break;
    case LVL_WASPADA:
      buzz = (t % 10000) < 200;
      led = (t % 500) < 250;
      break;
    default:
      led = (t % 5000) < 50;
      break;
  }
  digitalWrite(PIN_BUZZER, (buzz && !muted) ? HIGH : LOW);
  digitalWrite(PIN_LED, led ? HIGH : LOW);
}

// ---------------------------------------------------------------- Data JSON
static float r2(float v) { return isnan(v) ? v : roundf(v * 100.0f) / 100.0f; }

static String buildJson(const Reading &r, const EwsResult &e) {
  JsonDocument doc;
  doc["device_id"] = DEVICE_ID;
  doc["fw"] = FW_VERSION;
  doc["seq"] = seq;
  doc["ts"] = commsEpoch();             // 0 bila waktu belum sinkron
  doc["uptime_s"] = millis() / 1000;

  JsonObject t = doc["temp"].to<JsonObject>();
  for (uint8_t c = 0; c < CH_COUNT; c++) t[CHANNEL_NAME[c]] = r2(r.temp[c]);

  JsonObject h = doc["headspace"].to<JsonObject>();
  h["co2_ppm"] = r2(r.co2);
  h["temp"] = r2(r.tHead);
  h["rh"] = r2(r.rhHead);

  JsonObject s = doc["ews"].to<JsonObject>();
  s["level"] = LEVEL_NAME[e.level];
  s["dT"] = r2(e.dT);
  s["hotspot"] = e.hotspot >= 0 ? CHANNEL_NAME[e.hotspot] : nullptr;
  s["co2_leak"] = e.co2Leak;
  s["co2_max"] = r2(e.co2Max);
  s["stabilizing"] = e.stabilizing;
  s["cycle_h"] = r2(e.cycleHours);
  s["reason"] = e.reason;

  doc["sensor_flags"] = r.flags;

  String out;
  serializeJson(doc, out);
  return out;
}

static void printReadable(const Reading &r, const EwsResult &e) {
  Serial.println(F("-----------------------------------------------------------"));
  Serial.printf("#%lu  siklus %.1f jam%s\n", (unsigned long)seq, e.cycleHours,
                e.stabilizing ? "  (stabilisasi)" : "");
  for (uint8_t c = 0; c < CH_COUNT; c++) {
    if (isnan(r.temp[c])) Serial.printf("  T %-7s : --  (sensor tidak valid)\n", CHANNEL_NAME[c]);
    else Serial.printf("  T %-7s : %6.2f C\n", CHANNEL_NAME[c], r.temp[c]);
  }
  if (isnan(r.co2)) Serial.println("  CO2       : --  (SCD41 tidak valid)");
  else Serial.printf("  CO2       : %6.0f ppm  (maks siklus %.0f)  T %.1f C  RH %.1f %%\n",
                     r.co2, e.co2Max, r.tHead, r.rhHead);
  if (isnan(e.dT)) Serial.println("  dT        : --");
  else Serial.printf("  dT        : %+6.2f C  (titik panas: %s)\n", e.dT,
                     e.hotspot >= 0 ? CHANNEL_NAME[e.hotspot] : "-");
  Serial.printf("  STATUS    : %s  - %s\n", LEVEL_NAME[e.level], e.reason);
}

// ---------------------------------------------------------------- Siklus ukur
static void runSample() {
  uint32_t now = millis();
  elapsedAccMs += (seq == 0) ? 0 : now - lastSampleMs;
  uint32_t elapsedMin = elapsedAccMs / 60000UL;
  elapsedAccMs %= 60000UL;
  lastSampleMs = now;
  seq++;

  Reading r;
  sensorsRead(r);
  EwsResult e;
  ewsEvaluate(r, elapsedMin, e);

  if (e.level != alarmLevel) muted = false;   // status berubah: buzzer aktif kembali
  alarmLevel = e.level;

  printReadable(r, e);
  String json = buildJson(r, e);
  commsFlushBuffer();                          // kirim data tertunda lebih dulu
  commsPublishOrBuffer(json);

  JsonDocument st;
  st["online"] = true;
  st["level"] = LEVEL_NAME[e.level];
  st["reason"] = e.reason;
  st["ts"] = commsEpoch();
  String stJson;
  serializeJson(st, stJson);
  commsPublishStatus(stJson);
}

// ---------------------------------------------------------------- Arduino
void setup() {
  Serial.begin(115200);
  // USB CDC (ESP32-C3): beri waktu Serial Monitor tersambung agar pesan boot terbaca
  for (uint32_t t0 = millis(); !Serial && millis() - t0 < 3000;) delay(10);
  delay(300);
  Serial.printf("\n%s v%s - node %s\n", FW_NAME, FW_VERSION, DEVICE_ID);
  Serial.println("Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta");

  pinMode(PIN_BUZZER, OUTPUT);
  pinMode(PIN_LED, OUTPUT);
  digitalWrite(PIN_BUZZER, LOW);

  if (!sensorsBegin()) Serial.println("[SENSOR] PERINGATAN: tidak ada sensor yang merespons");
  ewsBegin();
  commsBegin(handleCommand);
}

void loop() {
  commsLoop();
  pollSerial();
  alarmUpdate();

  if (sampleRequested || millis() - lastSampleMs >= SAMPLE_INTERVAL_MS) {
    sampleRequested = false;
    runSample();
  }
  delay(20);
}
