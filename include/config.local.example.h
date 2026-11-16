/*
 *  SILASE-EWS v1.0 - contoh konfigurasi lokal per node.
 *
 *  Salin berkas ini menjadi config.local.h (di folder yang sama), lalu isi.
 *  config.local.h tidak ikut masuk git, jadi kata sandi tidak tersebar.
 */
#pragma once

#define DEVICE_ID      "node-01"          // unik per node; tampil sebagai drum di dasbor

#define WIFI_SSID      "ISI_SSID"         // WiFi 2,4 GHz
#define WIFI_PASSWORD  "ISI_PASSWORD"

#define MQTT_HOST      "192.168.110.180"  // broker di jaringan lab (TCP, bukan WebSocket)
#define MQTT_PORT      1883
#define MQTT_USER      "silase-node"
#define MQTT_PASSWORD  "ISI_PASSWORD"
#define MQTT_USE_TLS   0                  // 1 = TLS (port 8883), 0 = tanpa TLS (port 1883)
