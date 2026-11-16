/*
 *  SILASE-EWS v1.0 - Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Berkas : sensors.h
 *  Fungsi : Antarmuka pembacaan sensor suhu multi-titik (DS18B20) dan
 *           CO2/suhu/kelembapan headspace (SCD41).
 */
#pragma once

#include <Arduino.h>

enum TempChannel : uint8_t { CH_BAWAH = 0, CH_TENGAH = 1, CH_ATAS = 2, CH_AMBIEN = 3, CH_COUNT = 4 };

// Bit penanda kesalahan sensor
#define FLAG_DS_BAWAH   (1u << 0)
#define FLAG_DS_TENGAH  (1u << 1)
#define FLAG_DS_ATAS    (1u << 2)
#define FLAG_DS_AMBIEN  (1u << 3)
#define FLAG_SCD41      (1u << 4)

struct Reading {
  float temp[CH_COUNT];   // derajat C, NAN bila tidak valid
  float co2;              // ppm, NAN bila tidak valid
  float tHead;            // suhu headspace dari SCD41
  float rhHead;           // kelembapan relatif headspace (%)
  uint16_t flags;         // kombinasi FLAG_* untuk sensor yang gagal/tidak valid
};

extern const char *const CHANNEL_NAME[CH_COUNT];

bool sensorsBegin();
void sensorsRead(Reading &r);
