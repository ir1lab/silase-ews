/*
 * ============================================================================
 *  SILASE-EWS v1.0
 *  Program Komputer Firmware Node Monitoring dan Peringatan Dini
 *  Fermentasi Pakan Berbasis ESP32
 *
 *  Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Pencipta : Irwan Novianto, S.T., M.Eng. [lengkapi pencipta lain]
 *
 *  Berkas   : config.h
 *  Fungsi   : Seluruh parameter konfigurasi node (pin, jaringan, ambang EWS).
 * ============================================================================
 */
#pragma once

#include <Arduino.h>

// Nilai asli per node (WiFi, akun MQTT, DEVICE_ID) sebaiknya ditulis di berkas
// config.local.h di folder yang sama; berkas itu tidak ikut masuk git.
// Salin config.local.example.h menjadi config.local.h lalu isi. Nilai di sana
// menggantikan nilai bawaan di bawah ini.
#if __has_include("config.local.h")
#include "config.local.h"
#endif

// ---------------------------------------------------------------- Identitas
#define FW_NAME     "SILASE-EWS"
#define FW_VERSION  "1.0.0"
#ifndef DEVICE_ID
#define DEVICE_ID   "node-01"          // unik per node, dipakai pada topik MQTT
#endif

// ---------------------------------------------------------------- Pin ESP32-C3 Super Mini
// Pin strapping (GPIO2, GPIO8, GPIO9) sengaja tidak dipakai: GPIO8 = LED bawaan papan,
// GPIO9 = tombol BOOT. GPIO20/GPIO21 (UART0) dicadangkan untuk pengembangan berikutnya.
#define PIN_ONEWIRE   4                // bus 1-Wire: 4x DS18B20 (pull-up 4k7 ke 3V3)
#define PIN_I2C_SDA   6                // SCD41
#define PIN_I2C_SCL   7
#define PIN_BUZZER    10               // buzzer aktif via transistor (aktif-tinggi)
#define PIN_LED       5                // LED status eksternal (aktif-tinggi)

// ---------------------------------------------------------------- DS18B20
// Urutan kanal: 0 = bawah, 1 = tengah, 2 = atas, 3 = ambien.
// Isi alamat ROM hasil pemindaian saat boot (lihat Serial Monitor).
// Bila seluruh alamat bernilai nol, firmware memakai urutan hasil pemindaian.
static const uint8_t DS_ADDR[4][8] = {
  {0, 0, 0, 0, 0, 0, 0, 0},   // bawah
  {0, 0, 0, 0, 0, 0, 0, 0},   // tengah
  {0, 0, 0, 0, 0, 0, 0, 0},   // atas
  {0, 0, 0, 0, 0, 0, 0, 0},   // ambien
};
#define DS_RESOLUTION_BITS 12

// ---------------------------------------------------------------- Jaringan
// ESP32-C3 hanya mendukung WiFi 2,4 GHz (WPA/WPA2).
#ifndef WIFI_SSID
#define WIFI_SSID      "ISI_SSID"
#define WIFI_PASSWORD  "ISI_PASSWORD"
#endif

// Daya pancar WiFi. Antena papan ESP32-C3 Super Mini tidak cocok dengan daya
// penuh: sambungan sering gagal walau SSID dan kata sandi benar. 8,5 dBm adalah
// nilai yang umum dipakai untuk papan ini; naikkan (mis. WIFI_POWER_11dBm atau
// WIFI_POWER_15dBm) hanya bila jarak ke router jauh dan sambungan tetap stabil.
#ifndef WIFI_TX_POWER
#define WIFI_TX_POWER  WIFI_POWER_8_5dBm
#endif

#ifndef MQTT_HOST
#define MQTT_HOST      "broker.contoh.ac.id"
#define MQTT_PORT      8883
#define MQTT_USER      "ISI_USER"
#define MQTT_PASSWORD  "ISI_PASSWORD"
#define MQTT_USE_TLS   1               // 1 = TLS (port 8883), 0 = tanpa TLS (port 1883)
#endif
#define MQTT_TOPIC_ROOT "silase-ews"   // topik: silase-ews/<DEVICE_ID>/{data,status,cmd}

// Sertifikat CA broker (format PEM) bila MQTT_USE_TLS = 1.
// Biarkan kosong untuk mode uji (koneksi terenkripsi tanpa verifikasi sertifikat).
static const char MQTT_CA_CERT[] = "";

#define NTP_SERVER     "pool.ntp.org"
#define TZ_OFFSET_SEC  (7 * 3600)      // WIB

// ---------------------------------------------------------------- Waktu
#define SAMPLE_INTERVAL_MS   (5UL * 60UL * 1000UL)   // interval pengukuran 5 menit
#define WIFI_TIMEOUT_MS      15000UL                 // batas tunggu WiFi saat boot
#define RECONNECT_INTERVAL_MS 30000UL                // jeda antarpercobaan sambung ulang WiFi/MQTT
#define MQTT_SOCKET_TIMEOUT_S 5                      // batas tunggu jawaban broker
#define MQTT_BUFFER_BYTES    1024                    // buffer PubSubClient; harus lebih besar dari satu payload data

// ---------------------------------------------------------------- Ambang EWS
// Nilai awal dari literatur; dikalibrasi ulang pada uji lapangan.
#define STABILIZATION_HOURS     72.0f   // alarm dT ditahan selama fase awal fermentasi
#define DT_WASPADA_C            2.0f    // dT >= 2 C di atas ambien: indikasi awal kerusakan aerobik
#define DT_BAHAYA_C             5.0f    // dT >= 5 C: kerusakan berlanjut
#define DT_CLEAR_C              1.5f    // histeresis: status pulih bila dT < 1,5 C
#define CONFIRM_SAMPLES         3       // jumlah sampel berurutan untuk menetapkan/mencabut status

#define CO2_ACTIVE_MIN_PPM      2000.0f // CO2 maksimum siklus harus melewati nilai ini
#define CO2_DROP_RATIO          0.5f    // CO2 < 50% dari maksimum siklus: indikasi kebocoran udara

// ---------------------------------------------------------------- Validasi rentang
#define T_MIN_VALID   -10.0f
#define T_MAX_VALID    80.0f
#define CO2_MIN_VALID   0.0f
#define CO2_MAX_VALID  40000.0f        // batas ukur SCD41
#define RH_MIN_VALID    0.0f
#define RH_MAX_VALID  100.0f

// ---------------------------------------------------------------- Buffer offline
#define BUFFER_FILE       "/buf_cur.jsonl"
#define BUFFER_FILE_OLD   "/buf_old.jsonl"
#define BUFFER_HALF_BYTES (256UL * 1024UL)   // rotasi berkas tiap 256 KB (total maks ~512 KB)
#define FLUSH_MAX_LINES   60                 // baris buffer yang dikirim per siklus
