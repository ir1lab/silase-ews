/*
 *  SILASE-EWS v1.0 - Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Berkas : ews.h
 *  Fungsi : Antarmuka logika peringatan dini (Early Warning System) fermentasi.
 */
#pragma once

#include "sensors.h"

enum AlertLevel : uint8_t { LVL_NORMAL = 0, LVL_WASPADA = 1, LVL_BAHAYA = 2 };

struct EwsResult {
  AlertLevel level;      // status terkonfirmasi
  float dT;              // suhu internal tertinggi - suhu ambien (C), NAN bila tak terhitung
  int8_t hotspot;        // kanal internal terpanas (0..2), -1 bila tidak ada
  bool co2Leak;          // indikasi kebocoran udara dari penurunan CO2
  bool stabilizing;      // masih dalam fase stabilisasi awal
  float cycleHours;      // umur siklus fermentasi (jam)
  float co2Max;          // CO2 tertinggi dalam siklus (ppm)
  const char *reason;    // keterangan singkat status
};

extern const char *const LEVEL_NAME[3];

void ewsBegin();
void ewsStartCycle();                                   // mulai siklus fermentasi baru
void ewsEvaluate(const Reading &r, uint32_t elapsedMin, EwsResult &out);
