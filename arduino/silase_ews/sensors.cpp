/*
 *  SILASE-EWS v1.0 - Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Berkas : sensors.cpp
 *  Fungsi : Pemindaian dan pemetaan probe DS18B20 ke posisi (bawah, tengah,
 *           atas, ambien), pengukuran single-shot SCD41, serta validasi
 *           rentang dasar setiap nilai pengukuran.
 */
#include "sensors.h"
#include "config.h"

#include <Wire.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <SensirionI2cScd4x.h>     // pustaka Sensirion I2C SCD4x versi 1.x

#ifndef SCD41_I2C_ADDR_62
#error "Pasang pustaka Sensirion I2C SCD4x versi 1.0.0 atau lebih baru"
#endif

const char *const CHANNEL_NAME[CH_COUNT] = {"bawah", "tengah", "atas", "ambien"};

static OneWire oneWire(PIN_ONEWIRE);
static DallasTemperature ds(&oneWire);
static SensirionI2cScd4x scd4x;

static DeviceAddress chAddr[CH_COUNT];
static bool chMapped[CH_COUNT] = {false, false, false, false};
static bool scdReady = false;

static bool addrIsZero(const uint8_t *a) {
  for (uint8_t i = 0; i < 8; i++) if (a[i] != 0) return false;
  return true;
}

static void printAddr(const uint8_t *a) {
  for (uint8_t i = 0; i < 8; i++) Serial.printf("0x%02X%s", a[i], i < 7 ? ", " : "");
}

static bool inRange(float v, float lo, float hi) {
  return !isnan(v) && v >= lo && v <= hi;
}

// Petakan probe ke kanal posisi: pakai alamat di config.h bila diisi,
// selain itu pakai urutan hasil pemindaian bus.
static void mapProbes() {
  uint8_t found = ds.getDeviceCount();
  Serial.printf("[DS18B20] %u probe terdeteksi:\n", found);

  DeviceAddress scanned[8];
  uint8_t n = 0;
  for (uint8_t i = 0; i < found && n < 8; i++) {
    if (ds.getAddress(scanned[n], i)) {
      Serial.printf("  #%u { ", n);
      printAddr(scanned[n]);
      Serial.println(" }");
      n++;
    }
  }

  bool useConfig = false;
  for (uint8_t c = 0; c < CH_COUNT; c++) if (!addrIsZero(DS_ADDR[c])) useConfig = true;

  for (uint8_t c = 0; c < CH_COUNT; c++) {
    if (useConfig) {
      memcpy(chAddr[c], DS_ADDR[c], 8);
      chMapped[c] = ds.isConnected(chAddr[c]);
    } else if (c < n) {
      memcpy(chAddr[c], scanned[c], 8);
      chMapped[c] = true;
    }
    if (chMapped[c]) ds.setResolution(chAddr[c], DS_RESOLUTION_BITS);
    Serial.printf("  kanal %-7s : %s\n", CHANNEL_NAME[c], chMapped[c] ? "OK" : "TIDAK DITEMUKAN");
  }
  if (!useConfig) {
    Serial.println("  PERINGATAN: alamat ROM belum diisi di config.h, pemetaan memakai urutan pemindaian.");
  }
}

bool sensorsBegin() {
  ds.begin();
  mapProbes();

  Wire.begin(PIN_I2C_SDA, PIN_I2C_SCL);
  scd4x.begin(Wire, SCD41_I2C_ADDR_62);
  scd4x.wakeUp();                    // sensor tidak membalas perintah ini; hasilnya diabaikan
  scd4x.stopPeriodicMeasurement();   // pastikan mode idle sebelum single-shot
  delay(500);
  uint64_t serial = 0;
  int16_t err = scd4x.getSerialNumber(serial);
  scdReady = (err == 0);
  Serial.printf("[SCD41] %s", scdReady ? "OK, serial " : "TIDAK MERESPONS\n");
  if (scdReady) Serial.printf("0x%04X%08X\n", (unsigned)(serial >> 32), (unsigned)(serial & 0xFFFFFFFFULL));

  bool anyDs = false;
  for (uint8_t c = 0; c < CH_COUNT; c++) anyDs |= chMapped[c];
  return anyDs || scdReady;
}

void sensorsRead(Reading &r) {
  r.flags = 0;
  r.co2 = r.tHead = r.rhHead = NAN;

  // --- Suhu multi-titik: picu konversi serentak, lalu baca per kanal
  ds.requestTemperatures();
  for (uint8_t c = 0; c < CH_COUNT; c++) {
    float t = NAN;
    if (chMapped[c]) t = ds.getTempC(chAddr[c]);
    // -127 = probe terputus; 85 = nilai reset (konversi tidak terjadi)
    if (t == DEVICE_DISCONNECTED_C || t == 85.0f || !inRange(t, T_MIN_VALID, T_MAX_VALID)) {
      t = NAN;
      r.flags |= (1u << c);
    }
    r.temp[c] = t;
  }

  // --- SCD41 single-shot (waktu ukur sekitar 5 detik)
  if (!scdReady) {
    r.flags |= FLAG_SCD41;
    return;
  }
  uint16_t co2 = 0;
  float t = NAN, rh = NAN;
  int16_t err = scd4x.measureSingleShot();
  if (err == 0) err = scd4x.readMeasurement(co2, t, rh);
  if (err != 0 || co2 == 0 ||
      !inRange((float)co2, CO2_MIN_VALID, CO2_MAX_VALID) ||
      !inRange(t, T_MIN_VALID, T_MAX_VALID) ||
      !inRange(rh, RH_MIN_VALID, RH_MAX_VALID)) {
    r.flags |= FLAG_SCD41;
    return;
  }
  r.co2 = co2;
  r.tHead = t;
  r.rhHead = rh;
}
