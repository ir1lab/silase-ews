<?php

declare(strict_types=1);

namespace Silase\Peringatan;

use PDO;

final class AlertRepo
{
    private const PILIH = 'SELECT a.*, d.nama, d.lokasi, u.nama AS diakui_nama FROM alerts a
                             JOIN devices d ON d.device_id = a.device_id
                             LEFT JOIN users u ON u.id = a.diakui_oleh';

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> peringatan yang masih berlangsung, BAHAYA lebih dulu */
    public function aktif(): array
    {
        return $this->pdo->query(
            self::PILIH . " WHERE a.selesai IS NULL
              ORDER BY CASE a.level WHEN 'BAHAYA' THEN 0 ELSE 1 END, a.mulai DESC, a.id DESC"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function riwayat(int $batas = 100): array
    {
        return $this->pdo->query(
            self::PILIH . ' WHERE a.selesai IS NOT NULL ORDER BY a.mulai DESC, a.id DESC LIMIT ' . $batas
        )->fetchAll();
    }

    public function jumlahAktif(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM alerts WHERE selesai IS NULL')->fetchColumn();
    }

    /** @return bool false bila peringatan tidak ada atau sudah diakui */
    public function akui(int $id, int $kini, ?int $userId = null): bool
    {
        $q = $this->pdo->prepare('UPDATE alerts SET diakui_pada = ?, diakui_oleh = ? WHERE id = ? AND diakui_pada IS NULL');
        $q->execute([gmdate('Y-m-d H:i:s', $kini), $userId, $id]);
        return $q->rowCount() > 0;
    }
}
