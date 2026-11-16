<?php

declare(strict_types=1);

namespace Silase;

use PDO;

/** Penyimpan nilai kunci sederhana (tabel pengaturan), mis. status gateway WhatsApp. */
final class Pengaturan
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ambil(string $kunci): ?string
    {
        $q = $this->pdo->prepare('SELECT nilai FROM pengaturan WHERE kunci = ?');
        $q->execute([$kunci]);
        $nilai = $q->fetchColumn();
        return $nilai === false ? null : $nilai;
    }

    /** @return array<string, mixed>|null */
    public function ambilJson(string $kunci): ?array
    {
        $nilai = $this->ambil($kunci);
        $data = $nilai === null ? null : json_decode($nilai, true);
        return is_array($data) ? $data : null;
    }

    public function simpan(string $kunci, ?string $nilai, int $kini): void
    {
        $waktu = gmdate('Y-m-d H:i:s', $kini);
        $q = $this->pdo->prepare('SELECT 1 FROM pengaturan WHERE kunci = ?');
        $q->execute([$kunci]);
        if ($q->fetchColumn() === false) {
            $this->pdo->prepare('INSERT INTO pengaturan (kunci, nilai, diubah_pada) VALUES (?, ?, ?)')
                ->execute([$kunci, $nilai, $waktu]);
        } else {
            $this->pdo->prepare('UPDATE pengaturan SET nilai = ?, diubah_pada = ? WHERE kunci = ?')
                ->execute([$nilai, $waktu, $kunci]);
        }
    }

    /** @param array<string, mixed> $data */
    public function simpanJson(string $kunci, array $data, int $kini): void
    {
        $this->simpan($kunci, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $kini);
    }
}
