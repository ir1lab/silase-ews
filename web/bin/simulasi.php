#!/usr/bin/env php
<?php

// Simulator node SILASE-EWS: mem-publish payload contoh ke broker, tanpa perangkat keras.
//
//   php bin/simulasi.php <skenario> [--device=node-01] [--langsung]
//
// Skenario:
//   normal     satu pembacaan saat ini + status online
//   riwayat    data mundur tiap 5 menit (--jam=24), untuk mengisi grafik
//   duplikat   payload yang sama dikirim dua kali
//   terlambat  pembacaan saat ini, lalu tiga pembacaan berumur 1 jam (buffer offline)
//   ts0        jam node belum sinkron (ts = 0), dikirim dua kali
//   null       probe tengah dan SCD41 gagal (nilai null, sensor_flags = 18)
//   bahaya     NORMAL -> WASPADA -> BAHAYA -> NORMAL, tiap 5 menit, berakhir saat ini
//   rusak      payload bukan JSON dan payload dengan kolom salah
//   offline    last will dari broker: {"online":false}
//   semua      semua skenario di atas kecuali riwayat
//
// --langsung : tanpa broker, payload langsung diproses ke basis data (uji cepat).

declare(strict_types=1);

use Silase\Catatan;
use Silase\Config;
use Silase\Db;
use Silase\Ingest\Ingestor;
use Silase\Ingest\Penerima;
use Silase\Mqtt;
use Silase\Simulasi\PembuatPayload as P;

require dirname(__DIR__) . '/vendor/autoload.php';

$dirWeb = dirname(__DIR__);
Config::muat($dirWeb);

$opsi = ['device' => 'node-01', 'jam' => '24', 'langsung' => false];
$skenario = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--langsung') {
        $opsi['langsung'] = true;
    } elseif (preg_match('/^--(device|jam)=(.+)$/', $arg, $m)) {
        $opsi[$m[1]] = $m[2];
    } elseif ($skenario === null && $arg[0] !== '-') {
        $skenario = $arg;
    } else {
        fwrite(STDERR, "Argumen tidak dikenal: $arg\n");
        exit(2);
    }
}
$daftar = ['normal', 'riwayat', 'duplikat', 'terlambat', 'ts0', 'null', 'bahaya', 'rusak', 'offline', 'semua'];
if (!in_array($skenario, $daftar, true)) {
    fwrite(STDERR, "Pakai: php bin/simulasi.php <" . implode('|', $daftar) . "> [--device=node-01] [--jam=24] [--langsung]\n");
    exit(2);
}

$device = $opsi['device'];
$akar = Mqtt::akarTopik();

// --- Saluran kirim: broker MQTT atau langsung ke Ingestor
if ($opsi['langsung']) {
    $penerima = new Penerima(
        new Ingestor(Db::sambung()),
        new Catatan(Config::get('LOG_DIR', $dirWeb . '/storage/log'), STDERR),
        $akar,
    );
    $kirim = function (string $jenis, string $payload) use ($penerima, $akar, $device): void {
        echo str_pad($penerima->pesan("$akar/$device/$jenis", $payload), 10) . " <- $jenis\n";
    };
} else {
    $klien = Mqtt::sambung(
        'silase-simulator-' . $device,
        Config::get('SIM_MQTT_USER') ?? Config::get('MQTT_USER'),
        Config::get('SIM_MQTT_USER') !== null ? Config::get('SIM_MQTT_PASS') : Config::get('MQTT_PASS'),
        true,
    );
    $kirim = function (string $jenis, string $payload) use ($klien, $akar, $device): void {
        // Firmware mem-publish data dengan QoS 0 dan status sebagai retained.
        $klien->publish("$akar/$device/$jenis", $payload, 0, $jenis === 'status');
        $klien->loopOnce(microtime(true));
        echo "terkirim   -> $akar/$device/$jenis\n";
    };
}

$kini = time();
$data = fn (int $ts, array $ubah = []) => $kirim('data', P::json($device, $ts, $ubah));

$jalankan = [
    'normal' => function () use ($data, $kirim, $kini): void {
        $data($kini);
        $kirim('status', P::status('NORMAL', 'fermentasi stabil', $kini));
    },
    'riwayat' => function () use ($data, $kirim, $kini, $opsi): void {
        $n = max(1, (int) ((float) $opsi['jam'] * 12));
        for ($i = $n - 1; $i >= 0; $i--) {
            $jam = ($n - 1 - $i) / 12;
            // Siklus sudah lewat fase stabilisasi; dT bergelombang pelan di bawah ambang.
            $data($kini - $i * 300, [
                'cycle_h' => 80 + $jam,
                'ambien' => 27.5 + 2.5 * sin($jam / 24 * 2 * M_PI),
                'dT' => 0.9 + 0.5 * sin($jam / 7),
                'co2' => 8000 + 600 * sin($jam / 5),
            ]);
        }
        $kirim('status', P::status('NORMAL', 'fermentasi stabil', $kini));
    },
    'duplikat' => function () use ($data, $kini): void {
        $data($kini - 1);
        $data($kini - 1);
    },
    'terlambat' => function () use ($data, $kini): void {
        $data($kini - 2);
        foreach ([3900, 3600, 3300] as $umur) {
            $data($kini - $umur);
        }
    },
    'ts0' => function () use ($data): void {
        $ubah = ['seq' => 3, 'uptime_s' => 615];
        $data(0, $ubah);
        $data(0, $ubah);
    },
    'null' => function () use ($data, $kini): void {
        $data($kini - 3, ['null' => ['tengah', 'scd41']]);
    },
    'bahaya' => function () use ($data, $kirim, $kini): void {
        $urutan = [0.9, 2.6, 2.9, 5.4, 5.8, 1.0];
        $n = count($urutan);
        foreach ($urutan as $i => $dT) {
            $data($kini - ($n - 1 - $i) * 300 - 4, ['dT' => $dT, 'cycle_h' => 130 + $i / 12]);
        }
        $kirim('status', P::status('NORMAL', 'fermentasi stabil', $kini));
    },
    'rusak' => function () use ($kirim, $device, $kini): void {
        $kirim('data', '{"device_id":"' . $device . '","ts":');
        $salah = P::data($device, $kini - 5);
        $salah['ews']['level'] = 'GAWAT';
        $kirim('data', json_encode($salah));
        $tanpaTemp = P::data($device, $kini - 6);
        unset($tanpaTemp['temp']);
        $kirim('data', json_encode($tanpaTemp));
    },
    'offline' => function () use ($kirim): void {
        $kirim('status', '{"online":false}');
    },
];

$urutan = $skenario === 'semua'
    ? ['normal', 'duplikat', 'terlambat', 'ts0', 'null', 'bahaya', 'rusak', 'offline']
    : [$skenario];
foreach ($urutan as $nama) {
    echo "== $nama ==\n";
    $jalankan[$nama]();
}

if (isset($klien)) {
    $klien->disconnect();
}
