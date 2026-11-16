#!/usr/bin/env php
<?php

// Worker notifikasi: mengirim antrean WhatsApp, membuat pengingat BAHAYA,
// menandai pesan kedaluwarsa, dan memantau kesehatan gateway tiap 5 menit.
// Proses ini terpisah dari worker ingest, sehingga gangguan WhatsApp tidak
// pernah menghentikan penerimaan data.

declare(strict_types=1);

use Silase\Catatan;
use Silase\Config;
use Silase\Db;
use Silase\Notifikasi\Antrean;
use Silase\Notifikasi\PabrikNotifier;
use Silase\Notifikasi\Pengirim;
use Silase\Notifikasi\SurelSmtp;
use Silase\Pengaturan;

require dirname(__DIR__) . '/vendor/autoload.php';

$dirWeb = dirname(__DIR__);
Config::muat($dirWeb);
$catatan = new Catatan(Config::get('LOG_DIR', $dirWeb . '/storage/log'), STDERR);

$berhenti = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$berhenti): void { $berhenti = true; });
    pcntl_signal(SIGINT, function () use (&$berhenti): void { $berhenti = true; });
}
$tidur = function (int $detik) use (&$berhenti): void {
    for ($i = 0; $i < $detik && !$berhenti; $i++) {
        sleep(1);
    }
};

$wa = PabrikNotifier::dariEnv();
$catatan->info($wa === null
    ? 'WhatsApp belum dikonfigurasi: antrean hanya dicatat, tidak ada pesan yang dikirim'
    : 'Worker notifikasi berjalan, provider ' . $wa->nama());

while (!$berhenti) {
    try {
        $pdo = Db::sambung();
        $pengirim = new Pengirim(
            $pdo,
            $wa,
            new Antrean($pdo, PabrikNotifier::namaProvider()),
            new Pengaturan($pdo),
            $catatan,
            SurelSmtp::dariEnv(),
        );
        while (!$berhenti) {
            // Jeda acak 3-8 detik antarpesan; saat antrean kosong cukup cek tiap 10 detik.
            $tidur($pengirim->langkah(time()) ? random_int(3, 8) : 10);
        }
    } catch (Throwable $e) {
        $catatan->galat(get_class($e) . ': ' . $e->getMessage());
        $tidur(15);
    }
}
$catatan->info('Worker notifikasi berhenti');
