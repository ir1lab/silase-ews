<?php

declare(strict_types=1);

namespace Silase\Web;

/** Pembantu tampilan: escape, waktu WIB, dan angka gaya Indonesia. */
final class Format
{
    public const ZONA = 'Asia/Jakarta';

    public static function e(?string $teks): string
    {
        return htmlspecialchars($teks ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Epoch dari kolom DATETIME (disimpan UTC). */
    public static function epoch(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    /** Contoh: "16 Nov 2026 14.05 WIB". */
    public static function wib(?string $utc, bool $denganTanggal = true): string
    {
        if ($utc === null) {
            return '–';
        }
        // Nama bulan ditulis sendiri agar tidak bergantung pada ekstensi intl.
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        $w = (new \DateTimeImmutable('@' . self::epoch($utc)))->setTimezone(new \DateTimeZone(self::ZONA));
        $jam = $w->format('H.i') . ' WIB';
        return $denganTanggal
            ? $w->format('j') . ' ' . $bulan[(int) $w->format('n') - 1] . ' ' . $w->format('Y') . ' ' . $jam
            : $jam;
    }

    public static function lalu(int $detik): string
    {
        return match (true) {
            $detik < 60 => 'baru saja',
            $detik < 3600 => intdiv($detik, 60) . ' menit lalu',
            $detik < 86400 => intdiv($detik, 3600) . ' jam lalu',
            default => intdiv($detik, 86400) . ' hari lalu',
        };
    }

    /** Angka dengan koma desimal; null menjadi tanda pisah, bukan nol. */
    public static function angka(string|float|int|null $nilai, int $desimal = 1): string
    {
        return $nilai === null ? '–' : number_format((float) $nilai, $desimal, ',', '.');
    }

    /** Umur siklus dari jam, mis. "5 hari 3 jam". */
    public static function umur(string|float|null $jam): string
    {
        if ($jam === null) {
            return '–';
        }
        $jam = (int) floor((float) $jam);
        if ($jam < 1) {
            return 'kurang dari 1 jam';
        }
        if ($jam < 24) {
            return "$jam jam";
        }
        $sisa = $jam % 24;
        return intdiv($jam, 24) . ' hari' . ($sisa ? " $sisa jam" : '');
    }
}
