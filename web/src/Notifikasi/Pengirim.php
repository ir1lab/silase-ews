<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

use PDO;
use Silase\Catatan;
use Silase\Pengaturan;

/**
 * Mengerjakan antrean notifikasi: cek kesehatan gateway, pengingat BAHAYA,
 * kedaluwarsa, dan pengiriman dengan batas laju. Satu panggilan langkah()
 * mengirim paling banyak satu pesan; jeda antarpesan diatur pemanggil.
 */
final class Pengirim
{
    public const SELANG_CEK_S = 300;            // cek kesehatan gateway tiap 5 menit
    public const KEDALUWARSA_S = 7200;          // pesan yang belum terkirim 2 jam tidak dikirim lagi
    public const PERCOBAAN_MAKS = 3;
    public const MAKS_PER_JAM = 20;             // per nomor pengirim
    public const SELANG_PENGINGAT_S = 21600;    // pengingat BAHAYA tiap 6 jam
    public const PENGINGAT_MAKS = 3;
    public const KUNCI_STATUS = 'wa_status';

    public function __construct(
        private PDO $pdo,
        private ?WhatsAppNotifier $wa,
        private Antrean $antrean,
        private Pengaturan $pengaturan,
        private Catatan $catatan,
        private ?PengirimSurel $surel = null,
    ) {
    }

    /** @return bool true bila satu pesan terkirim (pemanggil lalu menunggu 3-8 detik) */
    public function langkah(int $kini): bool
    {
        $this->buatPengingat($kini);
        $this->kedaluwarsakan($kini);
        if ($this->wa === null) {
            return false;                       // WhatsApp belum dikonfigurasi; antrean tetap tercatat
        }
        if (!$this->periksaGateway($kini)) {
            return false;
        }
        return $this->kirimSatu($kini);
    }

    /** @return bool keadaan gateway terakhir yang diketahui */
    public function periksaGateway(int $kini, bool $paksa = false): bool
    {
        $lama = $this->pengaturan->ambilJson(self::KUNCI_STATUS);
        if (!$paksa && $lama !== null && $kini - (int) $lama['dicek'] < self::SELANG_CEK_S) {
            return (bool) $lama['ok'];
        }
        $s = $this->wa->status();
        $this->catatStatus($s['ok'], $s['status'], $kini, $lama);
        return $s['ok'];
    }

    /** Pengingat untuk BAHAYA yang belum diakui: tiap 6 jam, paling banyak 3 kali. */
    public function buatPengingat(int $kini): void
    {
        $q = $this->pdo->prepare(
            "SELECT id, mulai, pengingat_terkirim FROM alerts
              WHERE level = 'BAHAYA' AND selesai IS NULL AND diakui_pada IS NULL AND pengingat_terkirim < ?"
        );
        $q->execute([self::PENGINGAT_MAKS]);
        foreach ($q->fetchAll() as $a) {
            $ke = (int) $a['pengingat_terkirim'] + 1;
            if ($kini - strtotime($a['mulai'] . ' UTC') < $ke * self::SELANG_PENGINGAT_S) {
                continue;
            }
            $this->pdo->prepare('UPDATE alerts SET pengingat_terkirim = ? WHERE id = ?')->execute([$ke, $a['id']]);
            $n = $this->antrean->untukAlert((int) $a['id'], Pesan::PENGINGAT, $kini);
            $this->catatan->info("Pengingat ke-$ke untuk peringatan #{$a['id']}: $n pesan diantrekan");
        }
    }

    /** Pesan yang terlalu lama di antrean, atau peringatannya sudah pulih, tidak dikirim. */
    public function kedaluwarsakan(int $kini): void
    {
        $this->pdo->prepare(
            "UPDATE notifications SET status = 'kedaluwarsa', galat_terakhir = 'lebih dari 2 jam di antrean'
              WHERE status = 'antre' AND dibuat_pada < ?"
        )->execute([gmdate('Y-m-d H:i:s', $kini - self::KEDALUWARSA_S)]);

        $this->pdo->exec(
            "UPDATE notifications SET status = 'kedaluwarsa', galat_terakhir = 'peringatan sudah pulih'
              WHERE status = 'antre' AND jenis IN ('peringatan', 'pengingat')
                AND alert_id IN (SELECT id FROM alerts WHERE selesai IS NOT NULL)"
        );
    }

    private function kirimSatu(int $kini): bool
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE status = 'terkirim' AND dikirim_pada >= ?");
        $q->execute([gmdate('Y-m-d H:i:s', $kini - 3600)]);
        if ((int) $q->fetchColumn() >= self::MAKS_PER_JAM) {
            return false;                       // batas laju per jam tercapai; sisanya menunggu
        }

        $n = $this->pdo->query("SELECT * FROM notifications WHERE status = 'antre' ORDER BY dibuat_pada, id LIMIT 1")->fetch();
        if (!$n) {
            return false;
        }

        // Kabar pulih hanya untuk penerima yang memang menerima peringatannya.
        if ($n['jenis'] === Pesan::PULIH && !$this->pernahDiperingatkan($n)) {
            $this->tandai((int) $n['id'], 'kedaluwarsa', 'peringatannya tidak pernah terkirim');
            return false;
        }

        try {
            $this->wa->send($n['nomor'], $n['jenis'], $this->antrean->muat($n, $kini));
        } catch (GatewayTerputus $e) {
            // Bukan salah pesannya: tetap di antrean, tidak dihitung sebagai percobaan.
            $this->tandai((int) $n['id'], 'antre', $e->getMessage());
            $this->catatStatus(false, 'TERPUTUS', $kini, $this->pengaturan->ambilJson(self::KUNCI_STATUS));
            return false;
        } catch (KirimGagal $e) {
            $percobaan = (int) $n['percobaan'] + 1;
            $status = $percobaan >= self::PERCOBAAN_MAKS ? 'gagal' : 'antre';
            $this->pdo->prepare('UPDATE notifications SET status = ?, percobaan = ?, galat_terakhir = ? WHERE id = ?')
                ->execute([$status, $percobaan, mb_substr($e->getMessage(), 0, 255), $n['id']]);
            $this->catatan->galat("Notifikasi #{$n['id']} percobaan $percobaan gagal: " . $e->getMessage());
            return false;
        }

        $this->pdo->prepare(
            "UPDATE notifications SET status = 'terkirim', percobaan = ?, galat_terakhir = NULL, dikirim_pada = ? WHERE id = ?"
        )->execute([(int) $n['percobaan'] + 1, gmdate('Y-m-d H:i:s', $kini), $n['id']]);
        $this->catatan->info("Notifikasi #{$n['id']} ({$n['jenis']}) terkirim");
        return true;
    }

    /** @param array<string, mixed> $n */
    private function pernahDiperingatkan(array $n): bool
    {
        $q = $this->pdo->prepare(
            "SELECT 1 FROM notifications WHERE alert_id = ? AND nomor = ? AND status = 'terkirim'
                AND jenis IN ('peringatan', 'pengingat') LIMIT 1"
        );
        $q->execute([$n['alert_id'], $n['nomor']]);
        return $q->fetchColumn() !== false;
    }

    private function tandai(int $id, string $status, string $galat): void
    {
        $this->pdo->prepare('UPDATE notifications SET status = ?, galat_terakhir = ? WHERE id = ?')
            ->execute([$status, mb_substr($galat, 0, 255), $id]);
    }

    /**
     * Menyimpan status gateway (dibaca dasbor untuk banner) dan mengirim email
     * ke admin satu kali saat sambungan berubah dari tersambung menjadi terputus.
     *
     * @param array<string, mixed>|null $lama
     */
    private function catatStatus(bool $ok, string $status, int $kini, ?array $lama): void
    {
        $baruPutus = !$ok && ($lama === null || !empty($lama['ok']));
        $this->pengaturan->simpanJson(self::KUNCI_STATUS, ['ok' => $ok, 'status' => $status, 'dicek' => $kini], $kini);
        if (!$baruPutus) {
            return;
        }
        $this->catatan->galat("WhatsApp terputus (status $status)");
        if ($this->surel === null) {
            return;
        }
        try {
            $this->surel->kirim(
                'SILASE-EWS: WhatsApp terputus',
                "Gateway WhatsApp SILASE-EWS terputus (status: $status).\n"
                . "Buka halaman WhatsApp di dasbor lalu pindai ulang kode QR.\n"
                . "Peringatan tetap tercatat di dasbor; pesan yang tertunda dikirim setelah tersambung kembali."
            );
        } catch (\Throwable $e) {
            $this->catatan->galat('Email ke admin gagal: ' . $e->getMessage());
        }
    }
}
