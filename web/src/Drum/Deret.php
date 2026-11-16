<?php

declare(strict_types=1);

namespace Silase\Drum;

use Silase\Web\Format;

/** Menyusun deret waktu untuk grafik; rentang panjang dirata-ratakan per selang. */
final class Deret
{
    public const RENTANG = ['24j' => '24 jam', '7h' => '7 hari', 'siklus' => 'Satu siklus'];
    private const INTERVAL_NODE_S = 300;
    /** 7 hari data per 5 menit (2016 titik) masih ditampilkan apa adanya. */
    private const TITIK_MAKS = 2100;
    private const KOLOM = [
        'bawah' => 't_bawah', 'tengah' => 't_tengah', 'atas' => 't_atas', 'ambien' => 't_ambien',
        'dt' => 'dt', 'co2' => 'co2_ppm', 'rh' => 'hs_rh',
    ];

    /**
     * Awal rentang (epoch). "Satu siklus" dihitung dari umur siklus yang
     * dilaporkan firmware pada pembacaan terbaru.
     *
     * @param array<string, mixed>|null $terakhir
     */
    public static function awal(string $rentang, ?array $terakhir, int $kini): int
    {
        if ($rentang === 'siklus' && $terakhir !== null && $terakhir['cycle_h'] !== null) {
            return Format::epoch($terakhir['measured_at']) - (int) round((float) $terakhir['cycle_h'] * 3600);
        }
        return $kini - ($rentang === '24j' ? 86400 : 7 * 86400);
    }

    /**
     * @param list<array<string, mixed>> $baris hasil DrumRepo::deret
     * @return array<string, mixed> t (epoch ms) dan satu larik per besaran; null = tidak ada nilai
     */
    public static function susun(array $baris, int $dari, int $sampai): array
    {
        $selang = max(
            self::INTERVAL_NODE_S,
            (int) ceil(($sampai - $dari) / self::TITIK_MAKS / self::INTERVAL_NODE_S) * self::INTERVAL_NODE_S
        );

        $ember = [];
        $tanpaJam = 0;
        foreach ($baris as $b) {
            $t = Format::epoch($b['measured_at']);
            $tanpaJam += (int) $b['ts_valid'] === 0 ? 1 : 0;
            $kunci = $selang === self::INTERVAL_NODE_S ? $t : intdiv($t, $selang) * $selang;
            foreach (self::KOLOM as $nama => $kolom) {
                if ($b[$kolom] !== null) {
                    $ember[$kunci][$nama][] = (float) $b[$kolom];
                } else {
                    $ember[$kunci][$nama] ??= [];
                }
            }
        }

        $hasil = ['t' => []];
        foreach (array_keys(self::KOLOM) as $nama) {
            $hasil[$nama] = [];
        }
        foreach ($ember as $t => $nilai) {
            $hasil['t'][] = $t * 1000;
            foreach (array_keys(self::KOLOM) as $nama) {
                $v = $nilai[$nama] ?? [];
                // Rata-rata hanya dari nilai yang valid; tanpa nilai valid tetap null.
                $hasil[$nama][] = $v === [] ? null : round(array_sum($v) / count($v), 2);
            }
        }

        return $hasil + [
            'dari' => $dari * 1000,
            'sampai' => $sampai * 1000,
            'selang_s' => $selang,
            'tanpa_jam' => $tanpaJam,
        ];
    }
}
