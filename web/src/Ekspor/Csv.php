<?php

declare(strict_types=1);

namespace Silase\Ekspor;

use PDO;

/** Ekspor pembacaan ke CSV untuk analisis: pemisah koma, desimal titik, sel kosong = tidak ada nilai. */
final class Csv
{
    private const KOLOM = [
        'device_id', 'seq', 'uptime_s', 'ts_valid', 'late', 'fw',
        't_bawah', 't_tengah', 't_atas', 't_ambien', 'co2_ppm', 'hs_temp', 'hs_rh',
        'level', 'dt', 'hotspot', 'co2_leak', 'co2_max', 'stabilizing', 'cycle_h', 'reason', 'sensor_flags',
    ];

    /**
     * @param resource $ke aliran tujuan
     * @return int jumlah baris data yang ditulis
     */
    public static function tulis($ke, PDO $pdo, string $deviceId, int $dari, int $sampai): int
    {
        fputcsv($ke, array_merge(['waktu_wib', 'waktu_utc'], self::KOLOM, ['diterima_utc']), ',', '"', '');

        $q = $pdo->prepare(
            'SELECT * FROM readings WHERE device_id = ? AND measured_at >= ? AND measured_at <= ? ORDER BY measured_at, id'
        );
        $q->execute([$deviceId, gmdate('Y-m-d H:i:s', $dari), gmdate('Y-m-d H:i:s', $sampai)]);

        $n = 0;
        while ($b = $q->fetch()) {
            $baris = [gmdate('Y-m-d H:i:s', strtotime($b['measured_at'] . ' UTC') + 7 * 3600), $b['measured_at']];
            foreach (self::KOLOM as $k) {
                $baris[] = $b[$k] === null ? '' : self::aman((string) $b[$k]);
            }
            $baris[] = $b['received_at'];
            fputcsv($ke, $baris, ',', '"', '');
            $n++;
        }
        return $n;
    }

    /** Mencegah teks dari node dijalankan sebagai rumus saat CSV dibuka di spreadsheet. */
    private static function aman(string $nilai): string
    {
        if ($nilai !== '' && !is_numeric($nilai) && in_array($nilai[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $nilai;
        }
        return $nilai;
    }
}
