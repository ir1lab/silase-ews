<?php

declare(strict_types=1);

namespace Silase\Ingest;

use PDO;
use PDOException;
use Silase\Notifikasi\Antrean;
use Silase\Notifikasi\PabrikNotifier;
use Silase\Peringatan\PelacakPeringatan;

/** Menyimpan pembacaan dan status node. Semua waktu disimpan dalam UTC. */
final class Ingestor
{
    /** Selisih waktu terima terhadap ts yang membuat baris ditandai terlambat. */
    public const BATAS_TERLAMBAT_S = 600;
    /** ts lebih jauh dari ini di masa depan dianggap jam node rusak. */
    public const TOLERANSI_MASA_DEPAN_S = 86400;

    /** @param (\Closure(): int)|null $jam sumber waktu epoch, diganti pada uji */
    public function __construct(private PDO $pdo, private ?\Closure $jam = null, private ?PelacakPeringatan $pelacak = null)
    {
        $this->pelacak ??= new PelacakPeringatan($pdo, new Antrean($pdo, PabrikNotifier::namaProvider()));
    }

    public function terimaData(string $deviceId, string $json): HasilIngest
    {
        $b = PayloadValidator::data($json, $deviceId);
        $kini = $this->kini();

        if ($b['ts'] > $kini + self::TOLERANSI_MASA_DEPAN_S) {
            throw new PayloadTidakValid('ts terlalu jauh di masa depan');
        }

        $tsValid = $b['ts'] > 0;
        $b['ts_valid'] = $tsValid ? 1 : 0;
        // ts = 0: jam node belum sinkron, pakai waktu terima server.
        $b['measured_at'] = self::utc($tsValid ? $b['ts'] : $kini);
        $b['received_at'] = self::utc($kini);
        $b['late'] = ($tsValid && $kini - $b['ts'] > self::BATAS_TERLAMBAT_S) ? 1 : 0;
        $b['dedup_key'] = $tsValid ? 't:' . $b['ts'] : sprintf('b:%d:%d', $b['seq'], $b['uptime_s']);

        $this->pdo->beginTransaction();
        try {
            $lastTs = $this->pastikanDevice($deviceId);
            if (!$this->sisipkan($b)) {
                $this->pdo->rollBack();
                return new HasilIngest(false);
            }
            // Baris ts = 0 dianggap terbaru karena waktunya adalah waktu terima.
            // Node yang baru mengirim data pasti sedang tersambung (online = 1).
            $terbaru = !$tsValid || $b['ts'] > $lastTs;
            $this->pdo->prepare(
                'UPDATE devices SET online = 1, last_seen = ?, fw = COALESCE(?, fw), last_ts = ? WHERE device_id = ?'
            )->execute([$b['received_at'], $b['fw'], max($lastTs, $b['ts']), $deviceId]);
            // Hanya pembacaan terbaru yang boleh mengubah peringatan; sisa buffer
            // yang datang tidak berurutan cukup disimpan.
            if ($terbaru) {
                $this->pelacak->proses($b, $kini);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return new HasilIngest(true, $terbaru, (bool) $b['late'], $b);
    }

    /** Status retained dari node, atau last will {"online":false} dari broker. */
    public function terimaStatus(string $deviceId, string $json): void
    {
        if (!preg_match(PayloadValidator::POLA_DEVICE, $deviceId)) {
            throw new PayloadTidakValid('device_id pada topik tidak valid');
        }
        $s = PayloadValidator::status($json);
        $this->pastikanDevice($deviceId);
        // Last will tidak membawa level; level terakhir dipertahankan.
        $this->pdo->prepare(
            'UPDATE devices SET online = ?, status_level = COALESCE(?, status_level),
                    status_reason = COALESCE(?, status_reason) WHERE device_id = ?'
        )->execute([$s['online'] ? 1 : 0, $s['level'], $s['reason'], $deviceId]);
    }

    /** Mendaftarkan drum yang belum dikenal; mengembalikan last_ts-nya. */
    private function pastikanDevice(string $deviceId): int
    {
        $q = $this->pdo->prepare('SELECT last_ts FROM devices WHERE device_id = ?');
        $q->execute([$deviceId]);
        $lastTs = $q->fetchColumn();
        if ($lastTs !== false) {
            return (int) $lastTs;
        }
        $this->pdo->prepare('INSERT INTO devices (device_id, nama) VALUES (?, ?)')
            ->execute([$deviceId, $deviceId]);
        return 0;
    }

    /** @return bool false bila (device_id, dedup_key) sudah ada */
    private function sisipkan(array $b): bool
    {
        $kolom = array_keys($b);
        $sql = sprintf(
            'INSERT INTO readings (%s) VALUES (%s)',
            implode(', ', $kolom),
            implode(', ', array_fill(0, count($kolom), '?')),
        );
        try {
            $this->pdo->prepare($sql)->execute(array_values($b));
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $q = $this->pdo->prepare('SELECT 1 FROM readings WHERE device_id = ? AND dedup_key = ?');
            $q->execute([$b['device_id'], $b['dedup_key']]);
            if ($q->fetchColumn() === false) {
                throw $e; // pelanggaran batasan lain, bukan duplikat
            }
            return false;
        }
    }

    private function kini(): int
    {
        return $this->jam ? ($this->jam)() : time();
    }

    private static function utc(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch);
    }
}
