# SILASE-EWS v1.0

**Program Komputer Firmware Node Monitoring dan Peringatan Dini Fermentasi Pakan Berbasis ESP32**

Hak Cipta © 2026 Universitas Nahdlatul Ulama Yogyakarta

Firmware ini berjalan pada node sensor yang dipasang di reaktor fermentasi pakan (silase atau pakan komplit). Node memantau suhu tumpukan pada tiga lapis dan suhu ambien, serta CO₂, suhu, dan kelembapan di headspace. Dari data itu node menghitung selisih suhu internal terhadap ambien (ΔT) sebagai penanda dini kerusakan aerobik. Peringatan disampaikan lewat alarm lokal dan MQTT.

## Perangkat keras

| Komponen | Jumlah | Antarmuka | Pin ESP32-C3 |
|---|---|---|---|
| ESP32-C3 Super Mini | 1 | — | — |
| DS18B20 probe waterproof: bawah, tengah, atas, ambien | 4 | 1-Wire (satu bus) | GPIO4, pull-up 4,7 kΩ ke 3,3 V |
| Sensirion SCD41 (CO₂, suhu, RH) | 1 | I²C | SDA GPIO6, SCL GPIO7 |
| Buzzer aktif (lewat transistor) | 1 | digital | GPIO10 |
| LED status | 1 | digital | GPIO5 |

Probe DS18B20 dipasang dengan catu 3,3 V, bukan mode parasit. Pin strapping ESP32-C3 (GPIO2, GPIO8, GPIO9) tidak dipakai. Skematik rangkaian ada di `docs/SILASE-EWS_v1.0_Skematik.pdf`.

## Fitur

- **Pemetaan probe ke posisi.** Setiap probe dikenali lewat alamat ROM 64-bit dan dipetakan ke posisi bawah, tengah, atas, atau ambien. Alamat yang terdeteksi dicetak di Serial Monitor saat boot.
- **Pengukuran CO₂.** SCD41 bekerja dalam mode *single-shot* setiap interval pengukuran (bawaan 5 menit).
- **Validasi rentang dasar.** Probe yang terputus (−127 °C), nilai reset (85 °C), atau nilai di luar rentang fisik ditandai tidak valid. Nilai tidak valid tidak ikut dihitung dan tidak memicu alarm kualitas.
- **Peringatan dini dengan tiga tingkat** (NORMAL / WASPADA / BAHAYA):
  - ΔT antara suhu internal terpanas dan suhu ambien dibandingkan dengan ambang yang bisa diatur.
  - Status pulih memakai histeresis.
  - Status baru berlaku setelah terkonfirmasi beberapa sampel berturut-turut.
  - Alarm ΔT ditahan selama fase stabilisasi awal fermentasi.
  - Penurunan tajam CO₂ dibandingkan nilai maksimum siklus dibaca sebagai indikasi kebocoran udara.
- **Umur siklus tidak hilang saat restart.** Umur siklus dan CO₂ maksimum disimpan di memori non-volatil.
- **Konektivitas.** WiFi dengan penyambungan ulang otomatis, waktu dari NTP, dan MQTT dengan opsi TLS. Saat node terputus tidak wajar, *last will* otomatis mengirim status offline.
- **Buffer offline.** Selama koneksi terputus, data disimpan ke LittleFS dan dikirim ulang otomatis setelah koneksi pulih.
- **Alarm lokal non-blocking** lewat buzzer dan LED.

## Konfigurasi

Semua parameter ada di `include/config.h`: identitas node, pin, WiFi, broker MQTT, interval pengukuran, ambang EWS, dan batas validasi.

Isi juga alamat ROM DS18B20 di `DS_ADDR`. Cara mendapatkannya: nyalakan node dengan probe terpasang satu per satu, lalu catat alamat yang tercetak di Serial Monitor.

## Kompilasi dan unggah

- **PlatformIO:** `pio run -t upload`, lalu `pio device monitor`.
- **Arduino IDE:** buka folder `arduino/silase_ews/`, pilih board **ESP32C3 Dev Module** (core esp32 2.0.17 atau 3.3.x), aktifkan **USB CDC On Boot** agar Serial Monitor berjalan lewat USB-C, dan pasang pustaka berikut:
  - OneWire
  - DallasTemperature
  - Sensirion I2C SCD4x 1.0.0 atau lebih baru (kelas `SensirionI2cScd4x`; versi 0.4.0 tidak kompatibel)
  - PubSubClient
  - ArduinoJson 7

## Perintah

Perintah bisa dikirim lewat Serial Monitor atau topik MQTT `silase-ews/<DEVICE_ID>/cmd`:

| Perintah | Fungsi |
|---|---|
| `start_cycle` | Memulai siklus fermentasi baru (umur siklus dan CO₂ maksimum direset) |
| `sample_now` | Langsung mengukur |
| `mute` | Membisukan buzzer sampai status berubah |

## Format data

Data dikirim ke topik `silase-ews/<DEVICE_ID>/data`:

```json
{"device_id":"node-01","fw":"1.0.0","seq":12,"ts":1794150000,"uptime_s":3600,
 "temp":{"bawah":29.81,"tengah":31.06,"atas":30.44,"ambien":28.5},
 "headspace":{"co2_ppm":8450,"temp":29.9,"rh":96.2},
 "ews":{"level":"NORMAL","dT":2.56,"hotspot":"tengah","co2_leak":false,
        "co2_max":9120,"stabilizing":true,"cycle_h":1.0,"reason":"fase stabilisasi"},
 "sensor_flags":0}
```

- Status terakhir dikirim *retained* ke `silase-ews/<DEVICE_ID>/status`.
- Nilai sensor yang tidak valid dikirim sebagai `null`.
- Server sebaiknya menghapus duplikat berdasarkan `device_id` + `ts`, karena data dari buffer offline bisa terkirim ulang.

## Pustaka pihak ketiga (bukan bagian dari ciptaan)

| Pustaka | Pemilik |
|---|---|
| OneWire | Paul Stoffregen |
| DallasTemperature | Miles Burton dkk. |
| Sensirion I2C SCD4x dan Sensirion Core | Sensirion AG |
| PubSubClient | Nick O'Leary |
| ArduinoJson | Benoit Blanchon |
| Arduino core for ESP32 | Espressif Systems |

Lisensi masing-masing pustaka mengikuti repositori aslinya.

## Status verifikasi

Kode sudah dikompilasi untuk board ESP32C3 Dev Module (USB CDC On Boot aktif) dengan pustaka Sensirion I2C SCD4x 1.1.0, tanpa peringatan dari berkas SILASE-EWS, pada dua versi core esp32:

| Core esp32 | Ukuran program | RAM |
|---|---|---|
| 2.0.17 | 928 KB (70% flash) | 40 KB (12%) |
| 3.3.12 | 1.179 KB (89% flash) | 39 KB (12%) |

Bila pada core 3.x muncul pesan *Sketch too big*, pilih Partition Scheme **No OTA (2MB APP/2MB SPIFFS)**. Pengujian pada perangkat keras belum dilakukan.
