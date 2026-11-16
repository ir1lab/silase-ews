<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

use Silase\Web\Format;

/**
 * Isi pesan WhatsApp. Urutan parameter (nama drum, level, dT, alasan, waktu)
 * sama untuk teks gateway dan template Cloud API, supaya perpindahan provider
 * tidak mengubah isi pesan.
 */
final class Pesan
{
    public const PERINGATAN = 'peringatan';
    public const PENGINGAT = 'pengingat';
    public const PULIH = 'pulih';
    public const UJI = 'uji';

    /**
     * @param array{nama: string, lokasi?: ?string, level: string, dt?: string|float|null,
     *              hotspot?: ?string, alasan?: ?string, waktu: string} $d waktu dalam UTC
     * @return array{string, string, string, string, string} nama drum, level, dT, alasan, waktu
     */
    public static function parameter(array $d): array
    {
        $drum = $d['nama'] . (!empty($d['lokasi']) ? ' (' . $d['lokasi'] . ')' : '');
        $dt = isset($d['dt']) ? Format::angka($d['dt']) . ' C' : '-';
        return [$drum, $d['level'], $dt, self::alasan($d), Format::wib($d['waktu'])];
    }

    /** Teks biasa untuk gateway, paling banyak lima baris. */
    public static function teks(string $jenis, array $d): string
    {
        [$drum, $level, , $alasan, $waktu] = self::parameter($d);

        [$judul, $penutup] = match ($jenis) {
            self::PULIH => ['SILASE PULIH - NORMAL', 'Tetap pantau drum secara berkala.'],
            self::PENGINGAT => ["PENGINGAT SILASE - $level", 'Peringatan ini belum diakui. Segera periksa drum.'],
            self::UJI => ['PESAN UJI SILASE-EWS', 'Abaikan pesan ini.'],
            default => [
                "PERINGATAN SILASE - $level",
                $level === 'BAHAYA' ? 'Periksa tutup dan segel drum.' : 'Periksa drum pada kesempatan pertama.',
            ],
        };

        return implode("\n", [$judul, "Drum: $drum", $alasan, $waktu, $penutup]);
    }

    private static function alasan(array $d): string
    {
        if (($d['level'] ?? '') === 'NORMAL') {
            return isset($d['dt'])
                ? 'Kondisi kembali normal (selisih suhu ' . Format::angka($d['dt']) . ' C)'
                : 'Kondisi kembali normal';
        }
        $alasan = (string) ($d['alasan'] ?? '');
        if (stripos($alasan, 'CO2') !== false) {
            return 'CO2 turun tajam, indikasi kebocoran udara';
        }
        if ($alasan === 'uji') {
            return 'Notifikasi WhatsApp berfungsi';
        }
        if (!isset($d['dt'])) {
            return $alasan !== '' ? ucfirst($alasan) : 'Kondisi perlu diperiksa';
        }
        $lapis = !empty($d['hotspot']) ? ' (lapis ' . $d['hotspot'] . ')' : '';
        return 'Suhu naik ' . Format::angka($d['dt']) . ' C di atas ambien' . $lapis;
    }
}
