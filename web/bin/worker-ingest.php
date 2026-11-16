#!/usr/bin/env php
<?php

// Worker ingest: berlangganan silase-ews/+/data dan /status lalu menyimpannya.
// Berjalan terus; dijalankan lewat systemd (lihat deploy/silase-ingest.service).

declare(strict_types=1);

use Silase\Catatan;
use Silase\Config;
use Silase\Db;
use Silase\Ingest\Ingestor;
use Silase\Ingest\Penerima;
use Silase\Mqtt;

require dirname(__DIR__) . '/vendor/autoload.php';

$dirWeb = dirname(__DIR__);
Config::muat($dirWeb);
$catatan = new Catatan(Config::get('LOG_DIR', $dirWeb . '/storage/log'), STDERR);
$akar = Mqtt::akarTopik();

$berhenti = false;
$klien = null;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $henti = function () use (&$berhenti, &$klien): void {
        $berhenti = true;
        $klien?->interrupt();
    };
    pcntl_signal(SIGTERM, $henti);
    pcntl_signal(SIGINT, $henti);
}

$jeda = 2;
while (!$berhenti) {
    try {
        $buatPenerima = fn (): Penerima => new Penerima(new Ingestor(Db::sambung()), $catatan, $akar);
        $penerima = $buatPenerima();

        // Sesi tetap (bukan sesi bersih) dengan client id tetap.
        $klien = Mqtt::sambung(
            Config::get('MQTT_CLIENT_ID', 'silase-web-ingest'),
            Config::get('MQTT_USER'),
            Config::get('MQTT_PASS'),
            false,
        );

        $tangani = function (string $topik, string $pesan) use (&$penerima, $buatPenerima, $catatan): void {
            try {
                $penerima->pesan($topik, $pesan);
            } catch (PDOException $e) {
                // Sambungan basis data putus: sambung ulang dan coba sekali lagi.
                $catatan->galat('Basis data: ' . $e->getMessage() . ' - menyambung ulang');
                $penerima = $buatPenerima();
                $penerima->pesan($topik, $pesan);
            }
        };
        $klien->subscribe("$akar/+/data", $tangani, 1);
        $klien->subscribe("$akar/+/status", $tangani, 1);
        $catatan->info("Tersambung ke broker, berlangganan $akar/+/data dan $akar/+/status");

        $jeda = 2;
        $klien->loop(true);
    } catch (Throwable $e) {
        $catatan->galat(get_class($e) . ': ' . $e->getMessage());
    } finally {
        try {
            if ($klien?->isConnected()) {
                $klien->disconnect();
            }
        } catch (Throwable) {
        }
        $klien = null;
    }

    if (!$berhenti) {
        $catatan->info("Mencoba lagi dalam $jeda detik");
        sleep($jeda);
        $jeda = min($jeda * 2, 60);
    }
}
$catatan->info('Worker berhenti');
