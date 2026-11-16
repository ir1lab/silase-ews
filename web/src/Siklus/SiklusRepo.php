<?php

declare(strict_types=1);

namespace Silase\Siklus;

use PDO;

/** Siklus fermentasi per drum; hasil akhirnya diisi manual saat siklus ditutup. */
final class SiklusRepo
{
    public const HASIL = ['baik' => 'Baik', 'rusak' => 'Rusak'];

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function satu(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT s.*, d.nama FROM cycles s JOIN devices d ON d.device_id = s.device_id WHERE s.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** @return array<string, mixed>|null siklus yang belum ditutup untuk drum itu */
    public function berjalan(string $deviceId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM cycles WHERE device_id = ? AND selesai IS NULL ORDER BY mulai DESC, id DESC LIMIT 1');
        $q->execute([$deviceId]);
        return $q->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> terbaru dulu, yang masih berjalan paling atas */
    public function semua(int $batas = 200): array
    {
        return $this->pdo->query(
            'SELECT s.*, d.nama, u.nama AS dibuat_nama FROM cycles s
               JOIN devices d ON d.device_id = s.device_id
               LEFT JOIN users u ON u.id = s.dibuat_oleh
              ORDER BY CASE WHEN s.selesai IS NULL THEN 0 ELSE 1 END, s.mulai DESC, s.id DESC LIMIT ' . $batas
        )->fetchAll();
    }

    public function mulai(string $deviceId, ?string $bahan, ?string $catatan, ?int $userId, int $kini): int
    {
        $this->pdo->prepare(
            'INSERT INTO cycles (device_id, mulai, bahan_pakan, catatan, dibuat_oleh) VALUES (?, ?, ?, ?, ?)'
        )->execute([$deviceId, gmdate('Y-m-d H:i:s', $kini), $bahan, $catatan, $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return bool false bila siklus tidak ada atau sudah ditutup */
    public function tutup(int $id, string $hasil, ?string $ph, ?string $organoleptik, int $kini): bool
    {
        $q = $this->pdo->prepare(
            'UPDATE cycles SET selesai = ?, hasil = ?, ph_akhir = ?, catatan_organoleptik = ? WHERE id = ? AND selesai IS NULL'
        );
        $q->execute([gmdate('Y-m-d H:i:s', $kini), $hasil, $ph, $organoleptik, $id]);
        return $q->rowCount() > 0;
    }
}
