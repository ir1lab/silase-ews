<?php

declare(strict_types=1);

namespace Silase\Drum;

use Silase\Web\Format;

/** Kondisi satu drum untuk ditampilkan: gabungan status online, umur data, dan level EWS. */
final class Kondisi
{
    /** Data lebih tua dari ini dianggap "tidak ada data". */
    public const BATAS_BASI_S = 900;

    public const BAHAYA = 'bahaya';
    public const WASPADA = 'waspada';
    public const NORMAL = 'normal';
    public const OFFLINE = 'offline';
    public const TANPA_DATA = 'tanpa_data';

    /** Yang perlu perhatian tampil paling atas. */
    private const URUTAN = [self::BAHAYA => 0, self::WASPADA => 1, self::TANPA_DATA => 2, self::OFFLINE => 3, self::NORMAL => 4];

    /** Kelas Tailwind ditulis utuh agar ikut terbangun ke CSS. */
    private const GAYA = [
        self::BAHAYA => ['Bahaya', 'border-l-red-600', 'bg-red-600 text-white', 'text-red-700'],
        self::WASPADA => ['Waspada', 'border-l-amber-500', 'bg-amber-400 text-amber-950', 'text-amber-700'],
        self::NORMAL => ['Normal', 'border-l-emerald-600', 'bg-emerald-600 text-white', 'text-emerald-700'],
        self::OFFLINE => ['Offline', 'border-l-slate-400', 'bg-slate-500 text-white', 'text-slate-500'],
        self::TANPA_DATA => ['Tidak ada data', 'border-l-slate-400', 'bg-slate-500 text-white', 'text-slate-500'],
    ];

    private function __construct(
        public readonly string $kode,
        public readonly string $label,
        public readonly string $kelasTepi,
        public readonly string $kelasLencana,
        public readonly string $kelasTeks,
        public readonly ?int $umurDataDetik,
        public readonly int $urutan,
    ) {
    }

    /**
     * @param array<string, mixed> $device baris devices
     * @param array<string, mixed>|null $terakhir pembacaan terbaru drum itu
     */
    public static function dari(array $device, ?array $terakhir, int $kini): self
    {
        $umur = $device['last_seen'] !== null ? max(0, $kini - Format::epoch($device['last_seen'])) : null;

        if ($terakhir === null || $umur === null || $umur > self::BATAS_BASI_S) {
            // Last will broker membedakan node yang mati dari data yang sekadar terhenti.
            $kode = ((int) $device['online'] === 0 && $terakhir !== null) ? self::OFFLINE : self::TANPA_DATA;
        } elseif ((int) $device['online'] === 0) {
            $kode = self::OFFLINE;
        } else {
            $kode = strtolower((string) $terakhir['level']);
        }

        [$label, $tepi, $lencana, $teks] = self::GAYA[$kode];
        return new self($kode, $label, $tepi, $lencana, $teks, $umur, self::URUTAN[$kode]);
    }

    /** Level EWS masih bisa dipercaya hanya bila datanya segar. */
    public function segar(): bool
    {
        return in_array($this->kode, [self::BAHAYA, self::WASPADA, self::NORMAL], true);
    }

    /**
     * Nama sensor yang gagal dari bitmask sensor_flags firmware.
     *
     * @return list<string>
     */
    public static function sensorGagal(int $flags): array
    {
        $nama = ['Probe bawah', 'Probe tengah', 'Probe atas', 'Probe ambien', 'SCD41 (CO₂ dan RH)'];
        $gagal = [];
        foreach ($nama as $bit => $n) {
            if ($flags & (1 << $bit)) {
                $gagal[] = $n;
            }
        }
        return $gagal;
    }
}
