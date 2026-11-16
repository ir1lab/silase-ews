<?php

declare(strict_types=1);

namespace Silase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Silase\Catatan;
use Silase\Db;
use Silase\Ingest\Ingestor;
use Silase\Notifikasi\Antrean;
use Silase\Notifikasi\CloudApiNotifier;
use Silase\Notifikasi\GatewayNotifier;
use Silase\Notifikasi\GatewayTerputus;
use Silase\Notifikasi\HttpGagal;
use Silase\Notifikasi\HttpKlien;
use Silase\Notifikasi\KirimGagal;
use Silase\Notifikasi\PenerimaRepo;
use Silase\Notifikasi\Pengirim;
use Silase\Notifikasi\PengirimSurel;
use Silase\Notifikasi\Pesan;
use Silase\Notifikasi\WhatsAppNotifier;
use Silase\Pengaturan;
use Silase\Simulasi\PembuatPayload as P;

/** Peringatan, antrean notifikasi, dan pengirim WhatsApp dengan gateway tiruan. */
final class PeringatanTest extends TestCase
{
    private const KINI = 1794150000;        // 8 Nov 2026 22.00 WIB
    private const JAM = 3600;

    private PDO $pdo;
    private WaTiruan $wa;
    private SurelTiruan $surel;

    protected function setUp(): void
    {
        $this->pdo = Db::sqlite(':memory:', dirname(__DIR__) . '/sql/schema.sql');
        $this->wa = new WaTiruan();
        $this->surel = new SurelTiruan();
    }

    /** Pembacaan pada $saat dengan selisih suhu $dT (menentukan level). */
    private function baca(int $saat, float $dT, string $device = 'node-01'): void
    {
        (new Ingestor($this->pdo, fn (): int => $saat))
            ->terimaData($device, P::json($device, $saat, ['dT' => $dT]));
    }

    private function penerima(string $nomor, string $device = 'node-01', bool $aktif = true): void
    {
        (new PenerimaRepo($this->pdo))->tambah($device, 'Operator', $nomor);
        if (!$aktif) {
            $this->pdo->exec("UPDATE alert_recipients SET aktif = 0 WHERE nomor = '$nomor'");
        }
    }

    private function pengirim(?WhatsAppNotifier $wa): Pengirim
    {
        return new Pengirim($this->pdo, $wa, new Antrean($this->pdo), new Pengaturan($this->pdo), new Catatan(), $this->surel);
    }

    /** @return list<array<string, mixed>> */
    private function tabel(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return list<string> */
    private function kolom(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------ pencatatan peringatan

    public function testBarisPeringatanHanyaSaatLevelBerubah(): void
    {
        $t = self::KINI;
        foreach ([0.9, 2.6, 2.9, 2.7, 5.4, 5.8, 1.0, 0.8] as $i => $dT) {
            $this->baca($t + $i * 300, $dT);
        }

        $a = $this->tabel('SELECT * FROM alerts ORDER BY id');
        $this->assertCount(2, $a);

        $this->assertSame('WASPADA', $a[0]['level']);
        $this->assertSame('kenaikan suhu internal', $a[0]['reason']);
        $this->assertEquals(2.6, $a[0]['dt']);
        $this->assertSame('tengah', $a[0]['hotspot']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $t + 300), $a[0]['mulai']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $t + 1200), $a[0]['selesai']);     // ditutup saat naik ke BAHAYA

        $this->assertSame('BAHAYA', $a[1]['level']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $t + 1200), $a[1]['mulai']);
        $this->assertSame(gmdate('Y-m-d H:i:s', $t + 1800), $a[1]['selesai']);     // pulih
    }

    public function testDataTidakBerurutanTidakMengubahPeringatan(): void
    {
        $this->baca(self::KINI, 0.9);
        $this->baca(self::KINI - self::JAM, 5.6);        // sisa buffer offline, lebih tua dari yang terbaru
        $this->assertCount(0, $this->tabel('SELECT * FROM alerts'));
    }

    public function testPeringatanTiapDrumTerpisah(): void
    {
        $this->baca(self::KINI, 5.5, 'node-01');
        $this->baca(self::KINI, 0.9, 'node-02');
        $this->baca(self::KINI + 300, 2.5, 'node-02');
        $this->assertSame(
            ['node-01:BAHAYA', 'node-02:WASPADA'],
            $this->kolom("SELECT device_id || ':' || level FROM alerts WHERE selesai IS NULL ORDER BY device_id")
        );
    }

    // ------------------------------------------------------------ antrean dan isi pesan

    public function testAntreanHanyaUntukPenerimaAktifDrumItu(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->baca(self::KINI - 300, 0.9, 'node-02');
        $this->penerima('6281111111111');
        $this->penerima('6282222222222');
        $this->penerima('6283333333333', 'node-01', false);
        $this->penerima('6284444444444', 'node-02');

        $this->baca(self::KINI, 2.6);
        $this->assertSame(
            ['6281111111111 peringatan antre', '6282222222222 peringatan antre'],
            $this->kolom("SELECT nomor || ' ' || jenis || ' ' || status FROM notifications ORDER BY nomor")
        );

        // Level tidak berubah: tidak ada pesan baru.
        $this->baca(self::KINI + 300, 2.8);
        $this->baca(self::KINI + 600, 2.7);
        $this->assertCount(2, $this->tabel('SELECT * FROM notifications'));

        // Pulih: satu kabar per penerima aktif.
        $this->baca(self::KINI + 900, 0.9);
        $this->assertCount(2, $this->tabel("SELECT * FROM notifications WHERE jenis = 'pulih'"));
    }

    public function testIsiPesanLimaBaris(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->pdo->exec("UPDATE devices SET nama = 'Drum 3', lokasi = 'Kandang A'");
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 5.4);
        $this->baca(self::KINI + 600, 1.0);

        [$peringatan, $pulih] = $this->kolom('SELECT isi FROM notifications ORDER BY id');
        $this->assertSame(
            "PERINGATAN SILASE - BAHAYA\n"
            . "Drum: Drum 3 (Kandang A)\n"
            . "Suhu naik 5,4 C di atas ambien (lapis tengah)\n"
            . "8 Nov 2026 22.00 WIB\n"
            . 'Periksa tutup dan segel drum.',
            $peringatan
        );
        $this->assertSame(
            "SILASE PULIH - NORMAL\n"
            . "Drum: Drum 3 (Kandang A)\n"
            . "Kondisi kembali normal (selisih suhu 1,0 C)\n"
            . "8 Nov 2026 22.10 WIB\n"
            . 'Tetap pantau drum secara berkala.',
            $pulih
        );
    }

    public function testPesanKebocoranDanParameterTemplate(): void
    {
        $d = [
            'nama' => 'Drum 1', 'lokasi' => null, 'level' => 'WASPADA', 'dt' => '1.20', 'hotspot' => 'atas',
            'alasan' => 'penurunan CO2, indikasi kebocoran udara', 'waktu' => '2026-11-08 15:00:00',
        ];
        $teks = Pesan::teks(Pesan::PERINGATAN, $d);
        $this->assertCount(5, explode("\n", $teks));
        $this->assertStringContainsString('CO2 turun tajam, indikasi kebocoran udara', $teks);
        $this->assertStringContainsString('Periksa drum pada kesempatan pertama.', $teks);

        // Urutan parameter sama dengan template Cloud API: nama drum, level, dT, alasan, waktu.
        $this->assertSame(
            ['Drum 1', 'WASPADA', '1,2 C', 'CO2 turun tajam, indikasi kebocoran udara', '8 Nov 2026 22.00 WIB'],
            Pesan::parameter($d)
        );
    }

    // ------------------------------------------------------------ pengiriman

    public function testPengirimMengirimSatuPesanPerLangkah(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->penerima('6282222222222');
        $this->baca(self::KINI, 5.5);

        $p = $this->pengirim($this->wa);
        $this->assertTrue($p->langkah(self::KINI + 1));
        $this->assertTrue($p->langkah(self::KINI + 6));
        $this->assertFalse($p->langkah(self::KINI + 12));               // antrean habis

        $this->assertSame(['6281111111111', '6282222222222'], array_column($this->wa->terkirim, 'nomor'));
        $this->assertSame('peringatan', $this->wa->terkirim[0]['jenis']);
        $this->assertStringStartsWith('PERINGATAN SILASE - BAHAYA', $this->wa->terkirim[0]['data']['teks']);
        $this->assertSame('BAHAYA', $this->wa->terkirim[0]['data']['parameter'][1]);
        $this->assertSame(['terkirim', 'terkirim'], $this->kolom('SELECT status FROM notifications ORDER BY id'));
        $this->assertNotNull($this->tabel('SELECT dikirim_pada FROM notifications')[0]['dikirim_pada']);
    }

    public function testGagalTigaKaliLaluBerhenti(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 2.6);

        $this->wa->lempar = new KirimGagal('nomor tidak terdaftar di WhatsApp');
        $p = $this->pengirim($this->wa);
        foreach ([1, 2, 3, 4] as $i) {
            $this->assertFalse($p->langkah(self::KINI + $i * 10));
        }
        $n = $this->tabel('SELECT * FROM notifications')[0];
        $this->assertSame('gagal', $n['status']);
        $this->assertEquals(3, $n['percobaan']);
        $this->assertSame('nomor tidak terdaftar di WhatsApp', $n['galat_terakhir']);
        $this->assertSame(3, $this->wa->panggilan);                      // percobaan keempat tidak terjadi
    }

    public function testGatewayTerputusPesanMenungguLaluTerkirim(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 5.5);

        $this->wa->lempar = new GatewayTerputus('gateway tidak terjangkau');
        $p = $this->pengirim($this->wa);
        $this->assertFalse($p->langkah(self::KINI + 1));

        $n = $this->tabel('SELECT * FROM notifications')[0];
        $this->assertSame('antre', $n['status']);
        $this->assertEquals(0, $n['percobaan']);                         // bukan salah pesannya
        $status = (new Pengaturan($this->pdo))->ambilJson(Pengirim::KUNCI_STATUS);
        $this->assertFalse($status['ok']);
        $this->assertCount(1, $this->surel->terkirim);
        $this->assertStringContainsString('WhatsApp terputus', $this->surel->terkirim[0][0]);

        // Selama masih terputus: tidak mencoba kirim, tidak mengirim email kedua.
        $this->wa->ok = false;
        $this->assertFalse($p->langkah(self::KINI + 400));
        $this->assertSame(1, $this->wa->panggilan);
        $this->assertCount(1, $this->surel->terkirim);

        // Tersambung lagi dalam 2 jam: pesan yang tertunda dikirim.
        $this->wa->ok = true;
        $this->wa->lempar = null;
        $this->assertTrue($p->langkah(self::KINI + 800));
        $this->assertSame('terkirim', $this->kolom('SELECT status FROM notifications')[0]);
        $this->assertTrue((new Pengaturan($this->pdo))->ambilJson(Pengirim::KUNCI_STATUS)['ok']);
    }

    public function testPesanTertundaLebihDariDuaJamKedaluwarsa(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 5.5);

        $this->wa->ok = false;
        $p = $this->pengirim($this->wa);
        $p->langkah(self::KINI + 60);
        $this->wa->ok = true;
        $this->assertFalse($p->langkah(self::KINI + 2 * self::JAM + 400));

        $n = $this->tabel('SELECT * FROM notifications')[0];
        $this->assertSame('kedaluwarsa', $n['status']);
        $this->assertSame([], $this->wa->terkirim);
    }

    public function testPeringatanYangSudahPulihTidakDikirim(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 2.6);

        $this->wa->ok = false;
        $p = $this->pengirim($this->wa);
        $p->langkah(self::KINI + 60);
        $this->baca(self::KINI + 600, 0.9);                              // pulih sebelum gateway tersambung
        $this->wa->ok = true;
        $p->langkah(self::KINI + 900);
        $p->langkah(self::KINI + 910);

        // Peringatannya tidak pernah sampai, jadi kabar pulihnya juga tidak dikirim.
        $this->assertSame(
            ['peringatan kedaluwarsa', 'pulih kedaluwarsa'],
            $this->kolom("SELECT jenis || ' ' || status FROM notifications ORDER BY id")
        );
        $this->assertSame([], $this->wa->terkirim);
        // Peringatan tetap tercatat walaupun WhatsApp gagal.
        $this->assertCount(1, $this->tabel('SELECT * FROM alerts'));
    }

    public function testKabarPulihDikirimBilaPeringatannyaTerkirim(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 2.6);
        $p = $this->pengirim($this->wa);
        $p->langkah(self::KINI + 5);
        $this->baca(self::KINI + 600, 0.9);
        $this->assertTrue($p->langkah(self::KINI + 605));
        $this->assertSame(['peringatan', 'pulih'], array_column($this->wa->terkirim, 'jenis'));
        $this->assertStringStartsWith('SILASE PULIH - NORMAL', $this->wa->terkirim[1]['data']['teks']);
    }

    public function testPengingatBahayaTiapEnamJamMaksimalTigaKali(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 5.5);
        $p = $this->pengirim($this->wa);

        $jumlah = fn (): int => count($this->tabel("SELECT * FROM notifications WHERE jenis = 'pengingat'"));
        $p->langkah(self::KINI + 5 * self::JAM);
        $this->assertSame(0, $jumlah());
        foreach ([6 => 1, 7 => 1, 12 => 2, 18 => 3, 24 => 3, 30 => 3] as $jam => $harapan) {
            $p->langkah(self::KINI + $jam * self::JAM + 1);
            $this->assertSame($harapan, $jumlah(), "jam ke-$jam");
        }
        $this->assertStringStartsWith(
            'PENGINGAT SILASE - BAHAYA',
            $this->kolom("SELECT isi FROM notifications WHERE jenis = 'pengingat'")[0]
        );
    }

    public function testTanpaPengingatSetelahDiakuiAtauUntukWaspada(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->baca(self::KINI - 300, 0.9, 'node-02');
        $this->penerima('6281111111111');
        $this->penerima('6282222222222', 'node-02');
        $this->baca(self::KINI, 5.5);                                    // BAHAYA, lalu diakui
        $this->baca(self::KINI, 2.6, 'node-02');                         // WASPADA
        $this->pdo->exec("UPDATE alerts SET diakui_pada = '2026-11-08 15:30:00' WHERE level = 'BAHAYA'");

        $this->pengirim($this->wa)->langkah(self::KINI + 7 * self::JAM);
        $this->assertCount(0, $this->tabel("SELECT * FROM notifications WHERE jenis = 'pengingat'"));
    }

    public function testBatasDuaPuluhPesanPerJam(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $antrean = new Antrean($this->pdo);
        for ($i = 0; $i < 22; $i++) {
            $antrean->uji('6281111111111', self::KINI);
        }
        $p = $this->pengirim($this->wa);
        for ($i = 0; $i < 25; $i++) {
            $p->langkah(self::KINI + $i * 5);
        }
        $this->assertCount(20, $this->wa->terkirim);

        // Satu jam setelah pesan pertama, jatah terbuka lagi.
        $this->assertTrue($p->langkah(self::KINI + self::JAM + 1));
        $this->assertCount(21, $this->wa->terkirim);
    }

    public function testKesehatanGatewayDicekTiapLimaMenit(): void
    {
        $p = $this->pengirim($this->wa);
        foreach ([0, 60, 299] as $detik) {
            $p->langkah(self::KINI + $detik);
        }
        $this->assertSame(1, $this->wa->cekStatus);
        $p->langkah(self::KINI + 300);
        $this->assertSame(2, $this->wa->cekStatus);
    }

    public function testTanpaKonfigurasiWhatsAppAntreanTetapTercatat(): void
    {
        $this->baca(self::KINI - 300, 0.9);
        $this->penerima('6281111111111');
        $this->baca(self::KINI, 5.5);

        $p = $this->pengirim(null);
        $this->assertFalse($p->langkah(self::KINI + 10));
        $this->assertSame(['antre'], $this->kolom('SELECT status FROM notifications'));
        $this->assertCount(1, $this->tabel('SELECT * FROM alerts'));
    }

    // ------------------------------------------------------------ provider

    public function testGatewayMengirimKeApiWaha(): void
    {
        $http = new HttpTiruan([[201, '{"id":"abc"}']]);
        $gw = new GatewayNotifier($http, 'http://silase-waha:3000/', 'kunci-rahasia');
        $gw->send('6281234567890', Pesan::PERINGATAN, ['teks' => "baris 1\nbaris 2", 'parameter' => ['a', 'b', 'c', 'd', 'e']]);

        [$metode, $url, $kepala, $badan] = $http->permintaan[0];
        $this->assertSame('POST', $metode);
        $this->assertSame('http://silase-waha:3000/api/sendText', $url);
        $this->assertSame('kunci-rahasia', $kepala['X-Api-Key']);
        $this->assertSame(
            ['session' => 'default', 'chatId' => '6281234567890@c.us', 'text' => "baris 1\nbaris 2"],
            json_decode($badan, true)
        );
        $this->assertSame('gateway', $gw->nama());
    }

    public function testGatewayMembedakanTerputusDanDitolak(): void
    {
        $data = ['teks' => 'x', 'parameter' => ['a', 'b', 'c', 'd', 'e']];
        $coba = function (array|\Throwable $jawaban) use ($data): string {
            try {
                (new GatewayNotifier(new HttpTiruan([$jawaban]), 'http://w', 'k'))->send('6281234567890', 'uji', $data);
                return 'terkirim';
            } catch (GatewayTerputus) {
                return 'terputus';
            } catch (KirimGagal) {
                return 'ditolak';
            }
        };
        $this->assertSame('terkirim', $coba([200, '{}']));
        $this->assertSame('terputus', $coba(new HttpGagal('Connection refused')));
        $this->assertSame('terputus', $coba([500, 'Internal Server Error']));
        $this->assertSame('terputus', $coba([422, '{"error":"Session status is not as expected"}']));
        $this->assertSame('ditolak', $coba([400, '{"message":"chatId tidak valid"}']));
        $this->assertSame('ditolak', $coba([401, '{"message":"Unauthorized"}']));
    }

    public function testStatusGateway(): void
    {
        $status = fn (array|\Throwable $jawaban): array
            => (new GatewayNotifier(new HttpTiruan([$jawaban]), 'http://w', 'k'))->status();

        $this->assertSame(['ok' => true, 'status' => 'WORKING'], $status([200, '{"name":"default","status":"WORKING"}']));
        $this->assertSame(['ok' => false, 'status' => 'SCAN_QR_CODE'], $status([200, '{"status":"SCAN_QR_CODE"}']));
        $this->assertSame(['ok' => false, 'status' => 'BELUM_DIBUAT'], $status([404, '{}']));
        $this->assertSame(['ok' => false, 'status' => 'KUNCI_DITOLAK'], $status([401, '{}']));
        $this->assertSame(['ok' => false, 'status' => 'TIDAK_TERJANGKAU'], $status(new HttpGagal('timeout')));
    }

    public function testCloudApiMengirimTemplateDenganLimaParameter(): void
    {
        $http = new HttpTiruan([[200, '{"messages":[{"id":"wamid.1"}]}'], [400, '{"error":{"message":"Template tidak ada"}}']]);
        $cloud = new CloudApiNotifier($http, 'token-rahasia', '1234567890', 'silase_peringatan');
        $param = ['Drum 3 (Kandang A)', 'BAHAYA', '5,4 C', 'Suhu naik 5,4 C di atas ambien (lapis tengah)', '8 Nov 2026 22.00 WIB'];
        $cloud->send('6281234567890', Pesan::PERINGATAN, ['teks' => 'tidak dipakai', 'parameter' => $param]);

        [$metode, $url, $kepala, $badan] = $http->permintaan[0];
        $this->assertSame('POST', $metode);
        $this->assertSame('https://graph.facebook.com/v21.0/1234567890/messages', $url);
        $this->assertSame('Bearer token-rahasia', $kepala['Authorization']);
        $b = json_decode($badan, true);
        $this->assertSame('6281234567890', $b['to']);
        $this->assertSame('template', $b['type']);
        $this->assertSame('silase_peringatan', $b['template']['name']);
        $this->assertSame('id', $b['template']['language']['code']);
        $this->assertSame($param, array_column($b['template']['components'][0]['parameters'], 'text'));
        $this->assertSame('cloud_api', $cloud->nama());

        $this->expectException(KirimGagal::class);
        $cloud->send('6281234567890', Pesan::PERINGATAN, ['teks' => '', 'parameter' => $param]);
    }

    public function testNomorDiseragamkan(): void
    {
        $this->assertSame('6281234567890', PenerimaRepo::normalNomor('0812-3456-7890'));
        $this->assertSame('6281234567890', PenerimaRepo::normalNomor('+62 812 3456 7890'));
        $this->assertSame('6281234567890', PenerimaRepo::normalNomor('6281234567890'));
        $this->assertNull(PenerimaRepo::normalNomor('12345'));
        $this->assertNull(PenerimaRepo::normalNomor('0274123456'));       // bukan nomor seluler
        $this->assertNull(PenerimaRepo::normalNomor('08abc'));
        $this->assertSame('62812•••••890', PenerimaRepo::samarkan('6281234567890'));
    }
}
