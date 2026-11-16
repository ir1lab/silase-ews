<?php

declare(strict_types=1);

namespace Silase\Peringatan;

use PDO;
use Silase\Notifikasi\Antrean;
use Silase\Notifikasi\Pesan;

/**
 * Mencatat peringatan dari level EWS yang dilaporkan firmware.
 * Baris alerts baru hanya dibuat saat level berubah, bukan tiap pembacaan.
 */
final class PelacakPeringatan
{
    public function __construct(private PDO $pdo, private Antrean $antrean)
    {
    }

    /**
     * Dipanggil untuk pembacaan terbaru sebuah drum, di dalam transaksi ingest.
     *
     * @param array<string, mixed> $b baris readings yang baru disimpan
     */
    public function proses(array $b, int $kini): void
    {
        $q = $this->pdo->prepare('SELECT id, level FROM alerts WHERE device_id = ? AND selesai IS NULL ORDER BY id DESC LIMIT 1');
        $q->execute([$b['device_id']]);
        $buka = $q->fetch() ?: null;

        if ($b['level'] === 'NORMAL') {
            if ($buka !== null) {
                $this->tutup((int) $buka['id'], $b['measured_at']);
                $this->antrean->untukAlert((int) $buka['id'], Pesan::PULIH, $kini);
            }
            return;
        }

        if ($buka !== null && $buka['level'] === $b['level']) {
            return;                                     // level tidak berubah
        }
        if ($buka !== null) {
            $this->tutup((int) $buka['id'], $b['measured_at']);   // WASPADA <-> BAHAYA
        }
        $this->pdo->prepare(
            'INSERT INTO alerts (device_id, level, reason, dt, hotspot, mulai) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$b['device_id'], $b['level'], $b['reason'], $b['dt'], $b['hotspot'], $b['measured_at']]);
        $this->antrean->untukAlert((int) $this->pdo->lastInsertId(), Pesan::PERINGATAN, $kini);
    }

    private function tutup(int $alertId, string $waktu): void
    {
        $this->pdo->prepare('UPDATE alerts SET selesai = ? WHERE id = ?')->execute([$waktu, $alertId]);
    }
}
