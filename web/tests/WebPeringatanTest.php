<?php

declare(strict_types=1);

namespace Silase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Silase\Db;
use Silase\Ingest\Ingestor;
use Silase\Notifikasi\Pengirim;
use Silase\Notifikasi\WhatsAppNotifier;
use Silase\Pengaturan;
use Silase\Simulasi\PembuatPayload as P;
use Silase\Web\Aplikasi;
use Silase\Web\Sesi;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Halaman Peringatan dan pengaturan WhatsApp. */
final class WebPeringatanTest extends TestCase
{
    private const KINI = 1794150000;

    private PDO $pdo;
    private Sesi $sesi;
    private ?WhatsAppNotifier $wa = null;

    protected function setUp(): void
    {
        $this->pdo = Db::sqlite(':memory:', dirname(__DIR__) . '/sql/schema.sql');
        $this->sesi = Sesi::memori();
    }

    private function baca(int $saat, float $dT, string $device = 'node-01'): void
    {
        (new Ingestor($this->pdo, fn (): int => $saat))->terimaData($device, P::json($device, $saat, ['dT' => $dT]));
    }

    private function minta(string $metode, string $url, array $form = []): ResponseInterface
    {
        $app = Aplikasi::buat($this->pdo, fn (): int => self::KINI + 60, false, $this->wa, $this->sesi);
        return $app->handle((new ServerRequestFactory())->createServerRequest($metode, $url)->withParsedBody($form));
    }

    /** POST dengan token CSRF sesi yang benar. */
    private function kirim(string $url, array $form = []): ResponseInterface
    {
        return $this->minta('POST', $url, $form + ['csrf' => $this->sesi->token()]);
    }

    private function teks(ResponseInterface $r): string
    {
        return preg_replace('/\s+/', ' ', strip_tags((string) $r->getBody()));
    }

    public function testDaftarPeringatanAktifDanRiwayat(): void
    {
        $this->baca(self::KINI - 1800, 2.6);                 // WASPADA, lalu pulih
        $this->baca(self::KINI - 1500, 0.9);
        $this->baca(self::KINI - 600, 5.4);                  // BAHAYA, masih berlangsung
        $this->baca(self::KINI - 600, 2.6, 'node-02');       // WASPADA drum lain

        $r = $this->minta('GET', '/peringatan');
        $this->assertSame(200, $r->getStatusCode());
        $t = $this->teks($r);

        $this->assertStringContainsString('2 peringatan sedang berlangsung', $t);
        $this->assertStringContainsString('BAHAYA node-01 kenaikan suhu internal · ΔT 5,4 °C di lapis tengah', $t);
        $this->assertLessThan(strpos($t, 'WASPADA node-02'), strpos($t, 'BAHAYA node-01'));   // BAHAYA lebih dulu
        $this->assertStringContainsString('pengingat WhatsApp dikirim tiap 6 jam', $t);
        $this->assertMatchesRegularExpression('/Riwayat .*WASPADA node-01 kenaikan suhu internal/', $t);
        $this->assertSame(2, substr_count((string) $r->getBody(), '>Akui</button>'));

        // Lencana jumlah peringatan aktif tampil di menu semua halaman.
        $this->assertMatchesRegularExpression('/Peringatan\s+2\s+WhatsApp/', $this->teks($this->minta('GET', '/')));
    }

    public function testAkuiPeringatan(): void
    {
        $this->baca(self::KINI, 5.4);
        $id = (int) $this->pdo->query('SELECT id FROM alerts')->fetchColumn();
        $this->minta('GET', '/peringatan');                  // pengunjung memuat form (token dibuat)

        $r = $this->kirim("/peringatan/$id/akui");
        $this->assertSame(303, $r->getStatusCode());
        $this->assertSame('/peringatan', $r->getHeaderLine('Location'));
        $this->assertSame('2026-11-08 15:01:00', $this->pdo->query('SELECT diakui_pada FROM alerts')->fetchColumn());

        $t = $this->teks($this->minta('GET', '/peringatan'));
        $this->assertStringContainsString('Peringatan diakui', $t);
        $this->assertStringContainsString('Diakui 8 Nov 2026 22.01 WIB', $t);
        $this->assertStringNotContainsString('Peringatan diakui', $this->teks($this->minta('GET', '/peringatan')));   // sekali tampil

        // Mengakui dua kali tidak mengubah waktu pengakuan.
        $this->kirim("/peringatan/$id/akui");
        $this->assertStringContainsString('sudah diakui', $this->teks($this->minta('GET', '/peringatan')));
    }

    public function testFormTanpaTokenCsrfDitolak(): void
    {
        $this->baca(self::KINI, 5.4);
        $id = (int) $this->pdo->query('SELECT id FROM alerts')->fetchColumn();
        $this->sesi->token();

        foreach ([[], ['csrf' => 'salah'], ['csrf' => ['larik']]] as $form) {
            $this->assertSame(403, $this->minta('POST', "/peringatan/$id/akui", $form)->getStatusCode());
        }
        $this->assertSame(403, $this->minta('POST', '/whatsapp/penerima', ['device_id' => 'node-01', 'nama' => 'A', 'nomor' => '081234567890'])->getStatusCode());
        $this->assertSame(403, $this->minta('POST', '/whatsapp/uji', ['penerima_id' => 1])->getStatusCode());
        $this->assertNull($this->pdo->query('SELECT diakui_pada FROM alerts')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alert_recipients')->fetchColumn());
    }

    public function testKelolaPenerima(): void
    {
        $this->baca(self::KINI, 0.9);

        $this->assertSame(303, $this->kirim('/whatsapp/penerima', ['device_id' => 'node-01', 'nama' => 'Pak Budi', 'nomor' => '0812-3456-7890'])->getStatusCode());
        $baris = $this->pdo->query('SELECT * FROM alert_recipients')->fetchAll();
        $this->assertCount(1, $baris);
        $this->assertSame('6281234567890', $baris[0]['nomor']);          // diseragamkan ke 628xxx
        $this->assertEquals(1, $baris[0]['aktif']);
        $this->assertStringContainsString('Pak Budi ditambahkan', $this->teks($this->minta('GET', '/whatsapp')));

        // Masukan yang ditolak, masing-masing dengan pesannya.
        $tolak = [
            'Nomor WhatsApp tidak valid' => ['device_id' => 'node-01', 'nama' => 'X', 'nomor' => '12345'],
            'sudah terdaftar' => ['device_id' => 'node-01', 'nama' => 'Y', 'nomor' => '+62 812 3456 7890'],
            'Pilih drum yang terdaftar' => ['device_id' => 'tidak-ada', 'nama' => 'Z', 'nomor' => '081234567891'],
            'Nama penerima wajib diisi' => ['device_id' => 'node-01', 'nama' => '  ', 'nomor' => '081234567891'],
        ];
        foreach ($tolak as $pesan => $form) {
            $this->kirim('/whatsapp/penerima', $form);
            $this->assertStringContainsString($pesan, $this->teks($this->minta('GET', '/whatsapp')));
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM alert_recipients')->fetchColumn());

        $id = (int) $baris[0]['id'];
        $this->kirim("/whatsapp/penerima/$id/aktif");
        $this->assertEquals(0, $this->pdo->query('SELECT aktif FROM alert_recipients')->fetchColumn());
        $this->assertStringContainsString('nonaktif', $this->teks($this->minta('GET', '/whatsapp')));
        $this->kirim("/whatsapp/penerima/$id/hapus");
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM alert_recipients')->fetchColumn());
    }

    public function testPesanUjiHanyaKePenerimaTerdaftar(): void
    {
        $this->baca(self::KINI, 0.9);
        $this->kirim('/whatsapp/penerima', ['device_id' => 'node-01', 'nama' => 'Pak Budi', 'nomor' => '081234567890']);
        $id = (int) $this->pdo->query('SELECT id FROM alert_recipients')->fetchColumn();

        $this->kirim('/whatsapp/uji', ['penerima_id' => 999]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());

        $this->kirim('/whatsapp/uji', ['penerima_id' => $id]);
        $n = $this->pdo->query('SELECT * FROM notifications')->fetch();
        $this->assertSame('uji', $n['jenis']);
        $this->assertSame('6281234567890', $n['nomor']);
        $this->assertNull($n['alert_id']);
        $this->assertStringStartsWith("PESAN UJI SILASE-EWS\n", $n['isi']);

        $t = $this->teks($this->minta('GET', '/whatsapp'));
        $this->assertStringContainsString('62812•••••890 antre', $t);    // nomor disamarkan di tabel antrean
    }

    public function testHalamanWhatsAppTanpaKonfigurasi(): void
    {
        $t = $this->teks($this->minta('GET', '/whatsapp'));
        $this->assertStringContainsString('WhatsApp belum dikonfigurasi', $t);
        $this->assertStringNotContainsString('WhatsApp terputus', $t);
    }

    public function testKodeQrDanBannerSaatTerputus(): void
    {
        $this->wa = $wa = new WaQrTiruan();
        (new Pengaturan($this->pdo))->simpanJson(Pengirim::KUNCI_STATUS, ['ok' => false, 'status' => 'SCAN_QR_CODE', 'dicek' => self::KINI], self::KINI);

        // Banner merah di semua halaman selama WhatsApp terputus.
        $this->assertStringContainsString('WhatsApp terputus, pindai ulang QR', $this->teks($this->minta('GET', '/')));

        $r = $this->minta('GET', '/whatsapp');
        $html = (string) $r->getBody();
        $this->assertStringContainsString('Menunggu pemindaian kode QR', $this->teks($r));
        $this->assertStringContainsString('src="/whatsapp/qr.png?t=', $html);
        $this->assertSame(1, $wa->disiapkan);                            // sesi dinyalakan supaya QR tersedia

        $qr = $this->minta('GET', '/whatsapp/qr.png');
        $this->assertSame(200, $qr->getStatusCode());
        $this->assertSame('image/png', $qr->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $qr->getHeaderLine('Cache-Control'));

        // Setelah QR dipindai: tidak ada QR, banner langsung hilang.
        $wa->ok = true;
        $t = $this->teks($this->minta('GET', '/whatsapp'));
        $this->assertStringContainsString('Tersambung', $t);
        $this->assertStringNotContainsString('pindai ulang QR', $t);
        $this->assertSame(404, $this->minta('GET', '/whatsapp/qr.png')->getStatusCode());
        $this->assertStringNotContainsString('WhatsApp terputus', $this->teks($this->minta('GET', '/')));
    }
}
