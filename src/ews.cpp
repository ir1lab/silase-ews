/*
 *  SILASE-EWS v1.0 - Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Berkas : ews.cpp
 *  Fungsi : Logika peringatan dini kegagalan fermentasi pakan:
 *           - selisih suhu internal terpanas terhadap ambien (dT) dengan
 *             histeresis dan konfirmasi sampel berurutan;
 *           - indikasi kebocoran udara dari penurunan CO2 headspace;
 *           - penahanan alarm selama fase stabilisasi awal;
 *           - penyimpanan umur siklus dan CO2 maksimum di memori non-volatil
 *             sehingga tetap berlanjut setelah node dinyalakan ulang.
 */
#include "ews.h"
#include "config.h"

#include <Preferences.h>

const char *const LEVEL_NAME[3] = {"NORMAL", "WASPADA", "BAHAYA"};

static Preferences prefs;
static uint32_t cycleMin = 0;          // umur siklus (menit)
static float co2Max = 0.0f;
static AlertLevel confirmed = LVL_NORMAL;
static AlertLevel pending = LVL_NORMAL;
static uint8_t pendingCount = 0;

void ewsBegin() {
  prefs.begin("silase", false);
  cycleMin = prefs.getUInt("cycleMin", 0);
  co2Max = prefs.getFloat("co2Max", 0.0f);
  Serial.printf("[EWS] siklus berjalan %.1f jam, CO2 maks %.0f ppm\n", cycleMin / 60.0f, co2Max);
}

void ewsStartCycle() {
  cycleMin = 0;
  co2Max = 0.0f;
  confirmed = pending = LVL_NORMAL;
  pendingCount = 0;
  prefs.putUInt("cycleMin", cycleMin);
  prefs.putFloat("co2Max", co2Max);
  Serial.println("[EWS] siklus fermentasi baru dimulai");
}

static AlertLevel levelFromDt(float dT, AlertLevel current) {
  if (dT >= DT_BAHAYA_C) return LVL_BAHAYA;
  if (dT >= DT_WASPADA_C) return LVL_WASPADA;
  if (dT < DT_CLEAR_C) return LVL_NORMAL;
  return current;                      // zona histeresis: pertahankan status
}

void ewsEvaluate(const Reading &r, uint32_t elapsedMin, EwsResult &out) {
  cycleMin += elapsedMin;
  prefs.putUInt("cycleMin", cycleMin);
  out.cycleHours = cycleMin / 60.0f;
  out.stabilizing = out.cycleHours < STABILIZATION_HOURS;

  // --- Suhu internal terpanas dan dT terhadap ambien
  out.hotspot = -1;
  float tMax = NAN;
  for (uint8_t c = CH_BAWAH; c <= CH_ATAS; c++) {
    if (isnan(r.temp[c])) continue;
    if (isnan(tMax) || r.temp[c] > tMax) { tMax = r.temp[c]; out.hotspot = c; }
  }
  out.dT = (isnan(tMax) || isnan(r.temp[CH_AMBIEN])) ? NAN : tMax - r.temp[CH_AMBIEN];

  // --- CO2: catat maksimum siklus, deteksi penurunan tajam
  if (!isnan(r.co2) && r.co2 > co2Max) {
    co2Max = r.co2;
    prefs.putFloat("co2Max", co2Max);
  }
  out.co2Max = co2Max;
  out.co2Leak = !out.stabilizing && !isnan(r.co2) &&
                co2Max >= CO2_ACTIVE_MIN_PPM && r.co2 < CO2_DROP_RATIO * co2Max;

  // --- Status kandidat
  AlertLevel candidate = confirmed;
  if (out.stabilizing) {
    candidate = LVL_NORMAL;
    out.reason = "fase stabilisasi";
  } else if (isnan(out.dT)) {
    out.reason = "dT tidak terhitung (periksa sensor)";   // status dipertahankan
  } else {
    candidate = levelFromDt(out.dT, confirmed);
    out.reason = candidate == LVL_NORMAL ? "fermentasi stabil" : "kenaikan suhu internal";
  }
  if (out.co2Leak && candidate < LVL_WASPADA) {
    candidate = LVL_WASPADA;
    out.reason = "penurunan CO2, indikasi kebocoran udara";
  }

  // --- Konfirmasi: status berubah setelah CONFIRM_SAMPLES kandidat berurutan
  if (candidate == confirmed) {
    pendingCount = 0;
  } else {
    if (candidate != pending) { pending = candidate; pendingCount = 0; }
    if (++pendingCount >= CONFIRM_SAMPLES) { confirmed = candidate; pendingCount = 0; }
  }
  out.level = confirmed;
}
