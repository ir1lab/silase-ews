<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

use PDO;

/** Menaruh pesan ke tabel notifications. Pengiriman dikerjakan worker terpisah. */
final class Antrean
{
    public function __construct(private PDO $pdo, private string $provider = 'gateway')
    {
    }

    /**
     * Mengantrekan satu pesan per penerima aktif drum itu.
     *
     * @return int jumlah pesan yang diantrekan
     */
    public function untukAlert(int $alertId, string $jenis, int $kini): int
    {
        $alert = $this->alert($alertId);
        if ($alert === null) {
            return 0;
        }
        $isi = Pesan::teks($jenis, $this->data($alert, $jenis, $kini));

        $q = $this->pdo->prepare('SELECT nomor FROM alert_recipients WHERE device_id = ? AND aktif = 1 ORDER BY id');
        $q->execute([$alert['device_id']]);
        $nomor = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($nomor as $n) {
            $this->sisip($alertId, $n, $jenis, $isi, $kini);
        }
        return count($nomor);
    }

    public function uji(string $nomor, int $kini): void
    {
        $this->sisip(null, $nomor, Pesan::UJI, Pesan::teks(Pesan::UJI, self::dataUji($kini)), $kini);
    }

    /**
     * Data pesan untuk satu baris notifications (dipakai ulang saat mengirim).
     *
     * @param array<string, mixed> $notif
     * @return array{teks: string, parameter: array{string, string, string, string, string}}
     */
    public function muat(array $notif, int $kini): array
    {
        $alert = $notif['alert_id'] !== null ? $this->alert((int) $notif['alert_id']) : null;
        $data = $alert !== null ? $this->data($alert, $notif['jenis'], $kini) : self::dataUji($kini);
        return ['teks' => $notif['isi'], 'parameter' => Pesan::parameter($data)];
    }

    /** @return array<string, mixed>|null alert beserta nama dan lokasi drum */
    private function alert(int $alertId): ?array
    {
        $q = $this->pdo->prepare(
            'SELECT a.*, d.nama, d.lokasi FROM alerts a JOIN devices d ON d.device_id = a.device_id WHERE a.id = ?'
        );
        $q->execute([$alertId]);
        return $q->fetch() ?: null;
    }

    /**
     * @param array<string, mixed> $alert
     * @return array<string, mixed> masukan untuk Pesan
     */
    private function data(array $alert, string $jenis, int $kini): array
    {
        $d = [
            'nama' => $alert['nama'],
            'lokasi' => $alert['lokasi'],
            'level' => $alert['level'],
            'dt' => $alert['dt'],
            'hotspot' => $alert['hotspot'],
            'alasan' => $alert['reason'],
            'waktu' => $alert['mulai'],
        ];
        if ($jenis === Pesan::PULIH) {
            // Selisih suhu saat pulih diambil dari pembacaan terbaru drum itu.
            $q = $this->pdo->prepare('SELECT dt FROM readings WHERE device_id = ? ORDER BY measured_at DESC, id DESC LIMIT 1');
            $q->execute([$alert['device_id']]);
            $dt = $q->fetchColumn();
            $d['level'] = 'NORMAL';
            $d['dt'] = $dt === false ? null : $dt;
            $d['waktu'] = $alert['selesai'] ?? gmdate('Y-m-d H:i:s', $kini);
        } elseif ($jenis === Pesan::PENGINGAT) {
            $d['waktu'] = gmdate('Y-m-d H:i:s', $kini);
        }
        return $d;
    }

    /** @return array<string, mixed> */
    private static function dataUji(int $kini): array
    {
        return ['nama' => 'uji', 'level' => 'UJI', 'alasan' => 'uji', 'waktu' => gmdate('Y-m-d H:i:s', $kini)];
    }

    private function sisip(?int $alertId, string $nomor, string $jenis, string $isi, int $kini): void
    {
        $this->pdo->prepare(
            'INSERT INTO notifications (alert_id, nomor, jenis, provider, isi, status, percobaan, dibuat_pada)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        )->execute([$alertId, $nomor, $jenis, $this->provider, $isi, 'antre', gmdate('Y-m-d H:i:s', $kini)]);
    }
}
