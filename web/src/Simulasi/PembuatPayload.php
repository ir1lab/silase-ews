<?php

declare(strict_types=1);

namespace Silase\Simulasi;

/** Menyusun payload seperti buildJson() firmware v1.0, untuk simulasi dan uji. */
final class PembuatPayload
{
    /** Ambang firmware (include/config.h) agar level simulasi konsisten. */
    private const DT_WASPADA = 2.0;
    private const DT_BAHAYA = 5.0;
    private const JAM_STABILISASI = 72.0;

    /**
     * @param array{
     *   seq?: int, uptime_s?: int, ambien?: float, dT?: float, co2?: float,
     *   co2_max?: float, cycle_h?: float, null?: list<string>,
     *   level?: string, reason?: string
     * } $ubah  'null' berisi nama sensor yang gagal: bawah|tengah|atas|ambien|scd41
     * @return array<string, mixed>
     */
    public static function data(string $device, int $ts, array $ubah = []): array
    {
        $ambien = $ubah['ambien'] ?? 28.5;
        $dT = $ubah['dT'] ?? 0.9;
        $cycleH = $ubah['cycle_h'] ?? 120.0;
        $co2 = $ubah['co2'] ?? 8450.0;
        $gagal = $ubah['null'] ?? [];

        // Lapis tengah adalah titik terpanas pada data simulasi.
        $temp = [
            'bawah' => round($ambien + $dT - 0.8, 2),
            'tengah' => round($ambien + $dT, 2),
            'atas' => round($ambien + $dT - 0.4, 2),
            'ambien' => round($ambien, 2),
        ];
        $flags = 0;
        foreach (['bawah', 'tengah', 'atas', 'ambien'] as $bit => $kanal) {
            if (in_array($kanal, $gagal, true)) {
                $temp[$kanal] = null;
                $flags |= 1 << $bit;
            }
        }
        $headspace = ['co2_ppm' => $co2, 'temp' => round($ambien + 1.4, 2), 'rh' => 96.2];
        if (in_array('scd41', $gagal, true)) {
            $headspace = ['co2_ppm' => null, 'temp' => null, 'rh' => null];
            $flags |= 1 << 4;
        }

        // dT dan hotspot dihitung ulang dari kanal yang masih valid, seperti firmware.
        $hotspot = null;
        $tMaks = null;
        foreach (['bawah', 'tengah', 'atas'] as $kanal) {
            if ($temp[$kanal] !== null && ($tMaks === null || $temp[$kanal] > $tMaks)) {
                $tMaks = $temp[$kanal];
                $hotspot = $kanal;
            }
        }
        $dTHitung = ($tMaks === null || $temp['ambien'] === null) ? null : round($tMaks - $temp['ambien'], 2);

        $stabilisasi = $cycleH < self::JAM_STABILISASI;
        if ($stabilisasi) {
            [$level, $reason] = ['NORMAL', 'fase stabilisasi'];
        } elseif ($dTHitung === null) {
            [$level, $reason] = ['NORMAL', 'dT tidak terhitung (periksa sensor)'];
        } elseif ($dTHitung >= self::DT_BAHAYA) {
            [$level, $reason] = ['BAHAYA', 'kenaikan suhu internal'];
        } elseif ($dTHitung >= self::DT_WASPADA) {
            [$level, $reason] = ['WASPADA', 'kenaikan suhu internal'];
        } else {
            [$level, $reason] = ['NORMAL', 'fermentasi stabil'];
        }

        return [
            'device_id' => $device,
            'fw' => '1.0.0',
            'seq' => $ubah['seq'] ?? intdiv($ts, 300) % 100000,
            'ts' => $ts,
            'uptime_s' => $ubah['uptime_s'] ?? ($ts > 0 ? $ts % 864000 : 42),
            'temp' => $temp,
            'headspace' => $headspace,
            'ews' => [
                'level' => $ubah['level'] ?? $level,
                'dT' => $dTHitung,
                'hotspot' => $hotspot,
                'co2_leak' => false,
                'co2_max' => $ubah['co2_max'] ?? max($co2, 9120.0),
                'stabilizing' => $stabilisasi,
                'cycle_h' => round($cycleH, 2),
                'reason' => $ubah['reason'] ?? $reason,
            ],
            'sensor_flags' => $flags,
        ];
    }

    public static function json(string $device, int $ts, array $ubah = []): string
    {
        return json_encode(self::data($device, $ts, $ubah), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function status(string $level, string $reason, int $ts): string
    {
        return json_encode(['online' => true, 'level' => $level, 'reason' => $reason, 'ts' => $ts]);
    }
}
