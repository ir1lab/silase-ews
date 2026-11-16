<?php

declare(strict_types=1);

namespace Silase\Perintah;

use PDO;

/** Catatan siapa mengirim perintah apa dan kapan (tabel commands_log). */
final class PerintahRepo
{
    public function __construct(private PDO $pdo)
    {
    }

    public function catat(int $userId, string $deviceId, string $perintah, bool $terkirim, int $kini): void
    {
        $this->pdo->prepare(
            'INSERT INTO commands_log (user_id, device_id, perintah, terkirim, dikirim_pada) VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $deviceId, $perintah, $terkirim ? 1 : 0, gmdate('Y-m-d H:i:s', $kini)]);
    }

    /** @return list<array<string, mixed>> */
    public function terakhir(int $batas = 50): array
    {
        return $this->pdo->query(
            'SELECT l.*, u.nama AS pengirim, d.nama AS nama_drum FROM commands_log l
               JOIN users u ON u.id = l.user_id
               JOIN devices d ON d.device_id = l.device_id
              ORDER BY l.id DESC LIMIT ' . $batas
        )->fetchAll();
    }
}
