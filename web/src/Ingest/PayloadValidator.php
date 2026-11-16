<?php

declare(strict_types=1);

namespace Silase\Ingest;

/**
 * Memeriksa payload dari firmware v1.0 dan meratakannya menjadi kolom tabel.
 * Kontraknya mengikuti buildJson() di src/main.cpp firmware; nilai sensor yang
 * tidak valid datang sebagai null dan tetap null di sini.
 */
final class PayloadValidator
{
    public const LEVEL = ['NORMAL', 'WASPADA', 'BAHAYA'];
    public const HOTSPOT = ['bawah', 'tengah', 'atas'];
    public const POLA_DEVICE = '/^[A-Za-z0-9_-]{1,32}$/';

    /** @return array<string, mixed> kolom readings tanpa kolom waktu turunan */
    public static function data(string $json, string $deviceTopik): array
    {
        $p = self::urai($json);

        $device = $p['device_id'] ?? null;
        if (!is_string($device) || !preg_match(self::POLA_DEVICE, $device)) {
            throw new PayloadTidakValid('device_id tidak valid');
        }
        if ($device !== $deviceTopik) {
            throw new PayloadTidakValid("device_id \"$device\" tidak cocok dengan topik \"$deviceTopik\"");
        }

        $temp = self::objek($p, 'temp');
        $hs = self::objek($p, 'headspace');
        $ews = self::objek($p, 'ews');

        $level = $ews['level'] ?? null;
        if (!in_array($level, self::LEVEL, true)) {
            throw new PayloadTidakValid('ews.level tidak dikenal');
        }
        $hotspot = $ews['hotspot'] ?? null;
        if ($hotspot !== null && !in_array($hotspot, self::HOTSPOT, true)) {
            throw new PayloadTidakValid('ews.hotspot tidak dikenal');
        }

        return [
            'device_id' => $device,
            'ts' => self::bulat($p, 'ts', 0xFFFFFFFF),
            'seq' => self::bulat($p, 'seq', 0xFFFFFFFF),
            'uptime_s' => self::bulat($p, 'uptime_s', 0xFFFFFFFF),
            'fw' => self::teks($p, 'fw', 16),
            't_bawah' => self::desimal($temp, 'bawah', 'temp', 999.99),
            't_tengah' => self::desimal($temp, 'tengah', 'temp', 999.99),
            't_atas' => self::desimal($temp, 'atas', 'temp', 999.99),
            't_ambien' => self::desimal($temp, 'ambien', 'temp', 999.99),
            'co2_ppm' => self::desimal($hs, 'co2_ppm', 'headspace', 999999.99),
            'hs_temp' => self::desimal($hs, 'temp', 'headspace', 999.99),
            'hs_rh' => self::desimal($hs, 'rh', 'headspace', 999.99),
            'level' => $level,
            'dt' => self::desimal($ews, 'dT', 'ews', 999.99),
            'hotspot' => $hotspot,
            'co2_leak' => self::boolean($ews, 'co2_leak', 'ews'),
            'co2_max' => self::desimal($ews, 'co2_max', 'ews', 999999.99),
            'stabilizing' => self::boolean($ews, 'stabilizing', 'ews'),
            'cycle_h' => self::desimal($ews, 'cycle_h', 'ews', 999999.99),
            'reason' => self::teks($ews, 'reason', 120),
            'sensor_flags' => self::bulat($p, 'sensor_flags', 0xFFFF),
        ];
    }

    /**
     * Payload topik status: {"online":true,"level":..,"reason":..,"ts":..}
     * atau last will {"online":false}.
     *
     * @return array{online: bool, level: ?string, reason: ?string}
     */
    public static function status(string $json): array
    {
        $p = self::urai($json);
        if (!is_bool($p['online'] ?? null)) {
            throw new PayloadTidakValid('status.online harus boolean');
        }
        $level = $p['level'] ?? null;
        if ($level !== null && !in_array($level, self::LEVEL, true)) {
            throw new PayloadTidakValid('status.level tidak dikenal');
        }
        return ['online' => $p['online'], 'level' => $level, 'reason' => self::teks($p, 'reason', 120)];
    }

    /** @return array<string, mixed> */
    private static function urai(string $json): array
    {
        try {
            $p = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PayloadTidakValid('bukan JSON: ' . $e->getMessage());
        }
        if (!is_array($p) || array_is_list($p)) {
            throw new PayloadTidakValid('JSON harus berupa objek');
        }
        return $p;
    }

    /** @return array<string, mixed> */
    private static function objek(array $p, string $kunci): array
    {
        $v = $p[$kunci] ?? null;
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new PayloadTidakValid("$kunci harus berupa objek");
        }
        return $v;
    }

    private static function bulat(array $p, string $kunci, int $maks): int
    {
        $v = $p[$kunci] ?? null;
        if (!is_int($v) || $v < 0 || $v > $maks) {
            throw new PayloadTidakValid("$kunci harus bilangan bulat 0..$maks");
        }
        return $v;
    }

    /** Angka dua desimal sebagai string (untuk kolom DECIMAL), atau null. */
    private static function desimal(array $p, string $kunci, string $induk, float $maks): ?string
    {
        if (!array_key_exists($kunci, $p)) {
            throw new PayloadTidakValid("$induk.$kunci tidak ada");
        }
        $v = $p[$kunci];
        if ($v === null) {
            return null;
        }
        if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || abs($v) > $maks) {
            throw new PayloadTidakValid("$induk.$kunci harus angka atau null");
        }
        return number_format((float) $v, 2, '.', '');
    }

    private static function boolean(array $p, string $kunci, string $induk): int
    {
        if (!is_bool($p[$kunci] ?? null)) {
            throw new PayloadTidakValid("$induk.$kunci harus boolean");
        }
        return $p[$kunci] ? 1 : 0;
    }

    private static function teks(array $p, string $kunci, int $maks): ?string
    {
        $v = $p[$kunci] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new PayloadTidakValid("$kunci harus teks");
        }
        return mb_substr($v, 0, $maks);
    }
}
