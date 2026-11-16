<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

use PDO;

/** Penerima peringatan WhatsApp per drum (tabel alert_recipients). */
final class PenerimaRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Menyeragamkan nomor ke format 628xxx; null bila bukan nomor seluler Indonesia. */
    public static function normalNomor(string $masukan): ?string
    {
        $n = preg_replace('/[\s\-().+]/', '', $masukan);
        if (str_starts_with($n, '08')) {
            $n = '62' . substr($n, 1);
        }
        return preg_match('/^628\d{7,12}$/', $n) ? $n : null;
    }

    /** Contoh: 6281234567890 menjadi 62812•••••890. */
    public static function samarkan(string $nomor): string
    {
        return strlen($nomor) < 9 ? $nomor : substr($nomor, 0, 5) . str_repeat('•', strlen($nomor) - 8) . substr($nomor, -3);
    }

    /** @return list<array<string, mixed>> */
    public function semua(): array
    {
        return $this->pdo->query(
            'SELECT r.*, d.nama AS nama_drum FROM alert_recipients r JOIN devices d ON d.device_id = r.device_id
              ORDER BY d.nama, r.device_id, r.nama, r.id'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function satu(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM alert_recipients WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** @return bool false bila nomor itu sudah terdaftar untuk drum yang sama */
    public function tambah(string $deviceId, string $nama, string $nomor): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM alert_recipients WHERE device_id = ? AND nomor = ?');
        $q->execute([$deviceId, $nomor]);
        if ($q->fetchColumn() !== false) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO alert_recipients (device_id, nama, nomor, aktif) VALUES (?, ?, ?, 1)')
            ->execute([$deviceId, $nama, $nomor]);
        return true;
    }

    public function balikAktif(int $id): void
    {
        $this->pdo->prepare('UPDATE alert_recipients SET aktif = 1 - aktif WHERE id = ?')->execute([$id]);
    }

    public function hapus(int $id): void
    {
        $this->pdo->prepare('DELETE FROM alert_recipients WHERE id = ?')->execute([$id]);
    }

    /** @return list<array<string, mixed>> pesan terakhir di antrean, terbaru dulu */
    public function antreanTerakhir(int $batas = 15): array
    {
        return $this->pdo->query(
            'SELECT n.*, d.nama AS nama_drum FROM notifications n
               LEFT JOIN alerts a ON a.id = n.alert_id
               LEFT JOIN devices d ON d.device_id = a.device_id
              ORDER BY n.id DESC LIMIT ' . $batas
        )->fetchAll();
    }
}
