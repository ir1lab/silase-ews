/*
 *  SILASE-EWS v1.0 - Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Berkas : comms.cpp
 *  Fungsi : Koneksi WiFi dengan penyambungan ulang, sinkronisasi waktu NTP,
 *           publikasi MQTT (opsional TLS), penerimaan perintah jarak jauh,
 *           serta penyimpanan data ke LittleFS saat koneksi terputus dan
 *           pengiriman ulang otomatis setelah koneksi pulih.
 */
#include "comms.h"
#include "config.h"

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <PubSubClient.h>
#include <LittleFS.h>
#include <time.h>

#if MQTT_USE_TLS
static WiFiClientSecure net;
#else
static WiFiClient net;
#endif
static PubSubClient mqtt(net);
static CommandHandler cmdHandler = nullptr;

static String topicData, topicStatus, topicCmd;
static uint32_t lastWifiTry = 0, lastMqttTry = 0;
static const uint32_t RETRY_MS = 30000UL;

static void onMessage(char *topic, byte *payload, unsigned int len) {
  String cmd;
  cmd.reserve(len);
  for (unsigned int i = 0; i < len; i++) cmd += (char)payload[i];
  cmd.trim();
  Serial.printf("[MQTT] perintah diterima: %s\n", cmd.c_str());
  if (cmdHandler) cmdHandler(cmd);
}

static void connectWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  Serial.printf("[WiFi] menyambung ke %s ...\n", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < WIFI_TIMEOUT_MS) delay(250);
  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[WiFi] terhubung, IP %s\n", WiFi.localIP().toString().c_str());
    configTime(TZ_OFFSET_SEC, 0, NTP_SERVER);
  } else {
    Serial.println("[WiFi] gagal, data akan disimpan ke buffer");
  }
}

static bool connectMqtt() {
  if (mqtt.connected()) return true;
  if (WiFi.status() != WL_CONNECTED) return false;
  String clientId = String(FW_NAME) + "-" + DEVICE_ID;
  // Last will: status offline otomatis bila node terputus tidak wajar
  bool ok = mqtt.connect(clientId.c_str(), MQTT_USER, MQTT_PASSWORD,
                         topicStatus.c_str(), 1, true, "{\"online\":false}");
  if (ok) {
    mqtt.subscribe(topicCmd.c_str());
    Serial.println("[MQTT] terhubung ke broker");
  } else {
    Serial.printf("[MQTT] gagal terhubung (state %d)\n", mqtt.state());
  }
  return ok;
}

void commsBegin(CommandHandler onCommand) {
  cmdHandler = onCommand;
  String base = String(MQTT_TOPIC_ROOT) + "/" + DEVICE_ID;
  topicData = base + "/data";
  topicStatus = base + "/status";
  topicCmd = base + "/cmd";

  if (!LittleFS.begin(true)) Serial.println("[FS] LittleFS gagal dipasang");

#if MQTT_USE_TLS
  if (strlen(MQTT_CA_CERT) > 0) net.setCACert(MQTT_CA_CERT);
  else net.setInsecure();              // mode uji: tanpa verifikasi sertifikat
#endif
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setCallback(onMessage);
  mqtt.setBufferSize(MQTT_MAX_PACKET_SIZE);

  connectWifi();
  lastWifiTry = millis();
  connectMqtt();
  lastMqttTry = millis();
}

void commsLoop() {
  uint32_t now = millis();
  if (WiFi.status() != WL_CONNECTED && now - lastWifiTry > RETRY_MS) {
    lastWifiTry = now;
    connectWifi();
  }
  if (!mqtt.connected() && now - lastMqttTry > RETRY_MS) {
    lastMqttTry = now;
    connectMqtt();
  }
  mqtt.loop();
}

bool commsOnline() {
  return WiFi.status() == WL_CONNECTED && mqtt.connected();
}

uint32_t commsEpoch() {
  time_t t = time(nullptr);
  return t > 1700000000 ? (uint32_t)t : 0;   // dianggap valid setelah NTP sinkron
}

// ---------------------------------------------------------------- Buffer
static void bufferAppend(const String &line) {
  File f = LittleFS.open(BUFFER_FILE, FILE_APPEND);
  if (!f) { Serial.println("[FS] gagal menulis buffer"); return; }
  f.println(line);
  size_t sz = f.size();
  f.close();
  if (sz > BUFFER_HALF_BYTES) {         // rotasi: data tertua dibuang
    LittleFS.remove(BUFFER_FILE_OLD);
    LittleFS.rename(BUFFER_FILE, BUFFER_FILE_OLD);
  }
}

// Kirim isi satu berkas buffer; berkas dihapus hanya bila seluruh baris terkirim.
// Baris yang sudah terkirim dapat terkirim ulang bila proses terputus di tengah
// (server melakukan deduplikasi berdasarkan device_id + ts).
static bool flushFile(const char *path, uint16_t &budget) {
  if (!LittleFS.exists(path)) return true;
  File f = LittleFS.open(path, FILE_READ);
  if (!f) return false;
  bool done = true;
  while (f.available()) {
    if (budget == 0 || !commsOnline()) { done = false; break; }
    String line = f.readStringUntil('\n');
    line.trim();
    if (line.length() == 0) continue;
    if (!mqtt.publish(topicData.c_str(), line.c_str())) { done = false; break; }
    budget--;
    mqtt.loop();
  }
  f.close();
  if (done) LittleFS.remove(path);
  return done;
}

void commsFlushBuffer() {
  if (!commsOnline()) return;
  uint16_t budget = FLUSH_MAX_LINES;
  if (flushFile(BUFFER_FILE_OLD, budget)) {
    if (flushFile(BUFFER_FILE, budget) && budget < FLUSH_MAX_LINES) {
      Serial.println("[FS] buffer offline terkirim seluruhnya");
    }
  }
}

void commsPublishOrBuffer(const String &json) {
  if (commsOnline() && mqtt.publish(topicData.c_str(), json.c_str())) return;
  bufferAppend(json);
  Serial.println("[FS] data disimpan ke buffer offline");
}

void commsPublishStatus(const String &json) {
  if (commsOnline()) mqtt.publish(topicStatus.c_str(), json.c_str(), true);
}
