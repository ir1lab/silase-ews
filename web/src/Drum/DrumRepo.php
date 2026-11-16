<?php

declare(strict_types=1);

namespace Silase\Drum;

use PDO;

final class DrumRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function semua(): array
    {
        return $this->pdo->query('SELECT * FROM devices ORDER BY nama, device_id')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function satu(string $deviceId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM devices WHERE device_id = ?');
        $q->execute([$deviceId]);
        return $q->fetch() ?: null;
    }

    /**
     * Pembacaan terbaru tiap drum.
     *
     * @return array<string, array<string, mixed>> dikunci device_id
     */
    public function terakhirSemua(): array
    {
        $baris = $this->pdo->query(
            'SELECT r.* FROM readings r
               JOIN (SELECT device_id, MAX(measured_at) AS m FROM readings GROUP BY device_id) x
                 ON x.device_id = r.device_id AND x.m = r.measured_at
              ORDER BY r.id'
        )->fetchAll();
        $hasil = [];
        foreach ($baris as $b) {
            $hasil[$b['device_id']] = $b;      // bila waktunya kembar, id terbesar yang dipakai
        }
        return $hasil;
    }

    /** @return array<string, mixed>|null */
    public function terakhir(string $deviceId): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT * FROM readings WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1'
        );
        $q->execute([$deviceId]);
        return $q->fetch() ?: null;
    }

    /**
     * Deret waktu untuk grafik, urut naik.
     *
     * @return list<array<string, mixed>>
     */
    public function deret(string $deviceId, int $dari, int $sampai): array
    {
        $q = $this->pdo->prepare(
            'SELECT measured_at, ts_valid, t_bawah, t_tengah, t_atas, t_ambien, dt, co2_ppm, hs_rh
               FROM readings
              WHERE device_id = ? AND measured_at >= ? AND measured_at <= ?
              ORDER BY measured_at, id'
        );
        $q->execute([$deviceId, gmdate('Y-m-d H:i:s', $dari), gmdate('Y-m-d H:i:s', $sampai)]);
        return $q->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function peringatan(string $deviceId, int $batas = 20): array
    {
        $q = $this->pdo->prepare(
            'SELECT a.*, u.nama AS diakui_nama FROM alerts a
               LEFT JOIN users u ON u.id = a.diakui_oleh
              WHERE a.device_id = ? ORDER BY a.mulai DESC, a.id DESC LIMIT ' . (int) $batas
        );
        $q->execute([$deviceId]);
        return $q->fetchAll();
    }
}
