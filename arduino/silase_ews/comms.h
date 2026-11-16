/*
 *  SILASE-EWS v1.0 - Hak Cipta (c) 2026 Universitas Nahdlatul Ulama Yogyakarta
 *  Berkas : comms.h
 *  Fungsi : Antarmuka konektivitas (WiFi, NTP, MQTT) dan buffer data offline.
 */
#pragma once

#include <Arduino.h>

typedef void (*CommandHandler)(const String &cmd);

void commsBegin(CommandHandler onCommand);
void commsLoop();                            // dipanggil sesering mungkin di loop()
bool commsOnline();
uint32_t commsEpoch();                       // waktu Unix (0 bila belum sinkron NTP)
void commsPublishOrBuffer(const String &json);
void commsPublishStatus(const String &json); // status terakhir (retained)
void commsFlushBuffer();
