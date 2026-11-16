<?php

declare(strict_types=1);

namespace Silase\Ingest;

use Silase\Catatan;

/**
 * Menyalurkan pesan MQTT ke Ingestor berdasarkan topik.
 * Payload rusak dicatat lalu dibuang; hanya galat basis data yang diteruskan
 * ke pemanggil (worker akan menyambung ulang).
 */
final class Penerima
{
    public const DISIMPAN = 'disimpan';
    public const DUPLIKAT = 'duplikat';
    public const STATUS = 'status';
    public const RUSAK = 'rusak';
    public const DIABAIKAN = 'diabaikan';

    public function __construct(
        private Ingestor $ingestor,
        private Catatan $catatan,
        private string $akarTopik = 'silase-ews',
    ) {
    }

    public function pesan(string $topik, string $payload): string
    {
        $bagian = explode('/', $topik);
        if (count($bagian) !== 3 || $bagian[0] !== $this->akarTopik) {
            return self::DIABAIKAN;
        }
        [, $device, $jenis] = $bagian;

        try {
            if ($jenis === 'data') {
                $hasil = $this->ingestor->terimaData($device, $payload);
                if (!$hasil->disimpan) {
                    $this->catatan->info("$device: duplikat dibuang");
                    return self::DUPLIKAT;
                }
                $this->catatan->info(sprintf(
                    '%s: seq %d %s dT %s%s',
                    $device,
                    $hasil->baris['seq'],
                    $hasil->baris['level'],
                    $hasil->baris['dt'] ?? '-',
                    $hasil->late ? ' (terlambat)' : '',
                ));
                return self::DISIMPAN;
            }
            if ($jenis === 'status') {
                $this->ingestor->terimaStatus($device, $payload);
                return self::STATUS;
            }
        } catch (PayloadTidakValid $e) {
            $this->catatan->payloadRusak($topik, $e->getMessage(), $payload);
            return self::RUSAK;
        }
        return self::DIABAIKAN;
    }
}
