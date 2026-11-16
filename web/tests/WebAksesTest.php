<?php

declare(strict_types=1);

namespace Silase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Silase\Db;
use Silase\Ingest\Ingestor;
use Silase\Pengaturan;
use Silase\Pengguna\PenggunaRepo;
use Silase\Simulasi\PembuatPayload as P;
use Silase\Web\Aplikasi;
use Silase\Web\Sesi;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Login, peran, pengguna, siklus, perintah, dan ekspor. */
final class WebAksesTest extends TestCase
{
    private const KINI = 1794150000;        // 8 Nov 2026 22.00 WIB
    private const SANDI = 'kata-sandi-uji';

    private PDO $pdo;
    private Sesi $sesi;
    private PenggunaRepo $pengguna;
    private PerintahTiruan $perintah;
    private int $kini = self::KINI;
    /** @var array<string, int> */
    private array $id = [];

    protected function setUp(): void
    {
        $this->pdo = Db::sqlite(':memory:', dirname(__DIR__) . '/sql/schema.sql');
        $this->sesi = Sesi::memori();
        $this->perintah = new PerintahTiruan();
        $this->pengguna = new PenggunaRepo($this->pdo, new Pengaturan($this->pdo));
        foreach (['admin', 'operator', 'peneliti'] as $peran) {
            $this->id[$peran] = $this->pengguna->buat(ucfirst($peran) . ' Uji', "$peran@uji.test", $peran, self::SANDI, self::KINI);
        }
        (new Ingestor($this->pdo, fn (): int => self::KINI - 60))
            ->terimaData('node-01', P::json('node-01', self::KINI - 60, ['dT' => 5.4]));
    }

    private function sebagai(?string $peran): void
    {
        $this->sesi = Sesi::memori();
        if ($peran !== null) {
            $this->sesi->masuk($this->id[$peran], $this->kini);
        }
    }

    private function minta(string $metode, string $url, array $form = []): ResponseInterface
    {
        $app = Aplikasi::buat($this->pdo, fn (): int => $this->kini, false, null, $this->sesi, $this->perintah);
        $req = (new ServerRequestFactory())->createServerRequest($metode, $url)->withParsedBody($form);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $kueri);
        return $app->handle($req->withQueryParams($kueri));
    }

    private function kirim(string $url, array $form = []): ResponseInterface
    {
        return $this->minta('POST', $url, $form + ['csrf' => $this->sesi->token()]);
    }

    private function teks(ResponseInterface $r): string
    {
        return preg_replace('/\s+/', ' ', strip_tags((string) $r->getBody()));
    }

    /** Pesan sekali tampil dari tindakan terakhir. */
    private function kilat(): string
    {
        return $this->sesi->ambilKilat()['pesan'] ?? '';
    }

    private function nilai(string $sql): mixed
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    // ------------------------------------------------------------ masuk dan keluar

    public function testTanpaMasukSemuaHalamanDialihkan(): void
    {
        $this->sebagai(null);
        foreach (['/', '/drum/node-01', '/peringatan', '/siklus', '/perintah', '/ekspor', '/ekspor/unduh?device_id=node-01&dari=2026-11-01&sampai=2026-11-08', '/whatsapp', '/whatsapp/qr.png', '/pengguna', '/akun'] as $url) {
            $r = $this->minta('GET', $url);
            $this->assertSame(303, $r->getStatusCode(), $url);
            $this->assertSame('/masuk', $r->getHeaderLine('Location'), $url);
        }
        $api = $this->minta('GET', '/api/drum/node-01/deret');
        $this->assertSame(401, $api->getStatusCode());

        // Tindakan tanpa login juga ditolak dan tidak mengubah apa pun.
        $this->assertSame(303, $this->minta('POST', '/perintah', ['device_id' => 'node-01', 'perintah' => 'mute', 'yakin' => '1'])->getStatusCode());
        $this->assertSame([], $this->perintah->terkirim);
    }

    public function testMasukDanKeluar(): void
    {
        $this->sebagai(null);
        $r = $this->minta('GET', '/masuk');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringNotContainsString('Ringkasan', $this->teks($r));       // tanpa menu sebelum masuk

        $r = $this->kirim('/masuk', ['email' => 'Operator@UJI.test ', 'sandi' => self::SANDI]);
        $this->assertSame(303, $r->getStatusCode());
        $this->assertSame('/', $r->getHeaderLine('Location'));

        $t = $this->teks($this->minta('GET', '/'));
        $this->assertStringContainsString('Operator Uji · Operator', $t);
        $this->assertStringContainsString('Perintah', $t);
        $this->assertStringNotContainsString('WhatsApp', $t);                      // menu admin tidak tampil

        $this->assertSame('/masuk', $this->kirim('/keluar')->getHeaderLine('Location'));
        $this->assertSame('/masuk', $this->minta('GET', '/')->getHeaderLine('Location'));
    }

    public function testMasukGagalDanTerkunciSetelahLimaKali(): void
    {
        $this->sebagai(null);
        $this->minta('GET', '/masuk');

        $this->kirim('/masuk', ['email' => 'tidak-ada@uji.test', 'sandi' => self::SANDI]);
        $this->assertSame('Email atau kata sandi salah.', $this->kilat());        // pesan sama untuk email tak dikenal

        for ($i = 0; $i < 5; $i++) {
            $this->kirim('/masuk', ['email' => 'admin@uji.test', 'sandi' => 'salah-salah-salah']);
        }
        $this->kilat();
        // Terkunci: kata sandi yang benar pun ditolak.
        $r = $this->kirim('/masuk', ['email' => 'admin@uji.test', 'sandi' => self::SANDI]);
        $this->assertSame('/masuk', $r->getHeaderLine('Location'));
        $this->assertStringContainsString('Terlalu banyak percobaan. Coba lagi dalam 15 menit', $this->kilat());

        // Akun lain tidak ikut terkunci; setelah 15 menit kunci terbuka.
        $this->assertSame('/', $this->kirim('/masuk', ['email' => 'operator@uji.test', 'sandi' => self::SANDI])->getHeaderLine('Location'));
        $this->sebagai(null);
        $this->minta('GET', '/masuk');
        $this->kini += 901;
        $this->assertSame('/', $this->kirim('/masuk', ['email' => 'admin@uji.test', 'sandi' => self::SANDI])->getHeaderLine('Location'));
    }

    public function testMasukTanpaTokenCsrfDitolak(): void
    {
        $this->sebagai(null);
        $this->minta('GET', '/masuk');
        $r = $this->minta('POST', '/masuk', ['email' => 'admin@uji.test', 'sandi' => self::SANDI]);
        $this->assertSame(403, $r->getStatusCode());
        $this->assertSame('/masuk', $this->minta('GET', '/')->getHeaderLine('Location'));
    }

    public function testPenggunaNonaktifDanSesiKedaluwarsaDikeluarkan(): void
    {
        $this->sebagai('operator');
        $this->assertSame(200, $this->minta('GET', '/')->getStatusCode());

        $this->pengguna->ubah($this->id['operator'], 'operator', false);
        $this->assertSame('/masuk', $this->minta('GET', '/')->getHeaderLine('Location'));
        $this->minta('GET', '/masuk');
        $this->kirim('/masuk', ['email' => 'operator@uji.test', 'sandi' => self::SANDI]);
        $this->assertSame('Email atau kata sandi salah.', $this->kilat());

        $this->sebagai('admin');
        $this->kini += 12 * 3600 + 1;                                              // sesi berlaku 12 jam
        $this->assertSame('/masuk', $this->minta('GET', '/')->getHeaderLine('Location'));
    }

    // ------------------------------------------------------------ hak akses per peran

    public function testHakAksesPerPeran(): void
    {
        $alert = (int) $this->nilai('SELECT id FROM alerts');
        // [metode, url, form, peran yang boleh]
        $aturan = [
            ['GET', '/', [], ['admin', 'operator', 'peneliti']],
            ['GET', '/drum/node-01', [], ['admin', 'operator', 'peneliti']],
            ['GET', '/peringatan', [], ['admin', 'operator', 'peneliti']],
            ['GET', '/siklus', [], ['admin', 'operator', 'peneliti']],
            ['GET', '/ekspor', [], ['admin', 'operator', 'peneliti']],
            ['GET', '/akun', [], ['admin', 'operator', 'peneliti']],
            ['GET', '/perintah', [], ['admin', 'operator']],
            ['POST', '/perintah', ['device_id' => 'node-01', 'perintah' => 'mute', 'yakin' => '1'], ['admin', 'operator']],
            ['POST', "/peringatan/$alert/akui", [], ['admin', 'operator']],
            ['POST', '/drum/node-01/ubah', ['nama' => 'Drum 1'], ['admin', 'operator']],
            ['POST', '/siklus/mulai', ['device_id' => 'node-01', 'yakin' => '1'], ['admin', 'operator']],
            ['GET', '/whatsapp', [], ['admin']],
            ['POST', '/whatsapp/penerima', ['device_id' => 'node-01', 'nama' => 'A', 'nomor' => '081234567890'], ['admin']],
            ['GET', '/pengguna', [], ['admin']],
            ['POST', '/pengguna', ['nama' => 'B', 'email' => 'b@uji.test', 'peran' => 'operator', 'sandi' => self::SANDI], ['admin']],
        ];
        foreach ($aturan as [$metode, $url, $form, $boleh]) {
            foreach (['admin', 'operator', 'peneliti'] as $peran) {
                $this->sebagai($peran);
                $r = $metode === 'POST' ? $this->kirim($url, $form) : $this->minta('GET', $url);
                $diizinkan = $r->getStatusCode() !== 403;
                $this->assertSame(in_array($peran, $boleh, true), $diizinkan, "$peran $metode $url -> " . $r->getStatusCode());
            }
        }
    }

    public function testPenelitiTidakMelihatTombolTindakan(): void
    {
        $this->sebagai('peneliti');
        $html = (string) $this->minta('GET', '/peringatan')->getBody();
        $this->assertStringNotContainsString('>Akui</button>', $html);
        $this->assertStringContainsString('Belum diakui', $html);
        $this->assertStringNotContainsString('Ubah nama, lokasi', (string) $this->minta('GET', '/drum/node-01')->getBody());
        $this->assertStringNotContainsString('Mulai siklus baru', (string) $this->minta('GET', '/siklus')->getBody());

        $t = $this->teks($this->minta('GET', '/'));
        $this->assertStringNotContainsString('Perintah', $t);
        $this->assertStringNotContainsString('Pengguna', $t);
    }

    public function testAkuiMencatatSiapaYangMengakui(): void
    {
        $this->sebagai('operator');
        $this->kirim('/peringatan/' . $this->nilai('SELECT id FROM alerts') . '/akui');
        $this->assertEquals($this->id['operator'], $this->nilai('SELECT diakui_oleh FROM alerts'));
        $this->assertStringContainsString('oleh Operator Uji', $this->teks($this->minta('GET', '/peringatan')));
    }

    // ------------------------------------------------------------ akun dan pengguna

    public function testGantiKataSandiSendiri(): void
    {
        $this->sebagai('operator');
        $baru = 'sandi-baru-yang-panjang';
        $salah = [
            'Kata sandi lama salah.' => ['sandi_lama' => 'keliru', 'sandi_baru' => $baru, 'sandi_ulang' => $baru],
            'Kata sandi paling sedikit 10 karakter.' => ['sandi_lama' => self::SANDI, 'sandi_baru' => 'pendek', 'sandi_ulang' => 'pendek'],
            'Kata sandi baru dan ulangannya tidak sama.' => ['sandi_lama' => self::SANDI, 'sandi_baru' => $baru, 'sandi_ulang' => $baru . 'x'],
        ];
        foreach ($salah as $pesan => $form) {
            $this->kirim('/akun/sandi', $form);
            $this->assertSame($pesan, $this->kilat());
        }
        $this->kirim('/akun/sandi', ['sandi_lama' => self::SANDI, 'sandi_baru' => $baru, 'sandi_ulang' => $baru]);
        $this->assertSame('Kata sandi diganti.', $this->kilat());
        $this->assertSame(200, $this->minta('GET', '/akun')->getStatusCode());      // tetap masuk

        $this->assertIsString($this->pengguna->masuk('operator@uji.test', self::SANDI, $this->kini));
        $this->assertIsArray($this->pengguna->masuk('operator@uji.test', $baru, $this->kini));
        $this->assertStringStartsWith('$2y$', $this->nilai("SELECT password_hash FROM users WHERE email = 'operator@uji.test'"));
    }

    public function testAdminMengelolaPengguna(): void
    {
        $this->sebagai('admin');
        $baru = ['nama' => 'Bu Sari', 'email' => 'Sari@Uji.test', 'peran' => 'peneliti', 'sandi' => self::SANDI];
        $this->kirim('/pengguna', $baru);
        $this->assertStringContainsString('Bu Sari ditambahkan sebagai peneliti', $this->kilat());
        $idSari = (int) $this->nilai("SELECT id FROM users WHERE email = 'sari@uji.test'");
        $this->assertGreaterThan(0, $idSari);

        $tolak = [
            'Email itu sudah dipakai pengguna lain.' => $baru,
            'Alamat email tidak valid.' => ['email' => 'bukan-email'] + $baru,
            'Peran tidak dikenal.' => ['email' => 'x@uji.test', 'peran' => 'superadmin'] + $baru,
            'Kata sandi paling sedikit 10 karakter.' => ['email' => 'y@uji.test', 'sandi' => '123'] + $baru,
        ];
        foreach ($tolak as $pesan => $form) {
            $this->kirim('/pengguna', $form);
            $this->assertSame($pesan, $this->kilat());
        }
        $this->assertSame(4, (int) $this->nilai('SELECT COUNT(*) FROM users'));

        // Ubah peran, nonaktifkan, dan ganti kata sandi pengguna lain.
        $this->kirim("/pengguna/$idSari/ubah", ['peran' => 'operator', 'aktif' => '0']);
        $this->assertSame('operator', $this->nilai("SELECT peran FROM users WHERE id = $idSari"));
        $this->assertEquals(0, $this->nilai("SELECT aktif FROM users WHERE id = $idSari"));
        $this->kirim("/pengguna/$idSari/ubah", ['peran' => 'operator', 'aktif' => '1']);
        $this->kirim("/pengguna/$idSari/sandi", ['sandi' => 'sandi-pengganti-baru']);
        $this->assertIsArray($this->pengguna->masuk('sari@uji.test', 'sandi-pengganti-baru', $this->kini));

        $t = $this->teks($this->minta('GET', '/pengguna'));
        $this->assertStringContainsString('Bu Sari sari@uji.test', $t);
        $this->assertStringContainsString('Admin Uji admin@uji.test Anda', $t);
    }

    public function testAdminTerakhirTidakBisaDicabut(): void
    {
        $this->sebagai('admin');
        $id = $this->id['admin'];
        foreach ([['peran' => 'operator', 'aktif' => '1'], ['peran' => 'admin', 'aktif' => '0']] as $form) {
            $this->kirim("/pengguna/$id/ubah", $form);
            $this->assertSame('Harus ada paling sedikit satu admin aktif.', $this->kilat());
        }
        $this->assertSame('admin', $this->nilai("SELECT peran FROM users WHERE id = $id"));
        $this->assertEquals(1, $this->nilai("SELECT aktif FROM users WHERE id = $id"));

        // Dengan admin kedua, admin pertama boleh diturunkan.
        $this->pengguna->ubah($this->id['operator'], 'admin', true);
        $this->kirim("/pengguna/$id/ubah", ['peran' => 'operator', 'aktif' => '1']);
        $this->assertSame('operator', $this->nilai("SELECT peran FROM users WHERE id = $id"));
    }

    // ------------------------------------------------------------ drum, siklus, perintah

    public function testUbahDataDrum(): void
    {
        $this->sebagai('operator');
        $this->kirim('/drum/node-01/ubah', ['nama' => '  Drum 3 ', 'lokasi' => 'Kandang A', 'catatan' => 'dekat pintu']);
        $d = $this->pdo->query("SELECT * FROM devices WHERE device_id = 'node-01'")->fetch();
        $this->assertSame(['Drum 3', 'Kandang A', 'dekat pintu'], [$d['nama'], $d['lokasi'], $d['catatan']]);

        $this->kirim('/drum/node-01/ubah', ['nama' => ' ', 'lokasi' => '']);
        $this->assertSame('Nama drum wajib diisi.', $this->kilat());
        $this->assertSame('Drum 3', $this->nilai("SELECT nama FROM devices WHERE device_id = 'node-01'"));

        $this->assertStringContainsString('Drum 3 Kandang A · node-01', $this->teks($this->minta('GET', '/drum/node-01')));
    }

    public function testMulaiSiklusMengirimPerintahDanMencatat(): void
    {
        $this->sebagai('operator');
        $form = ['device_id' => 'node-01', 'bahan_pakan' => 'rumput gajah', 'catatan' => 'batch 1'];

        $this->kirim('/siklus/mulai', $form);                                        // tanpa centang konfirmasi
        $this->assertStringContainsString('Centang konfirmasi', $this->kilat());
        $this->assertSame([], $this->perintah->terkirim);

        $this->kirim('/siklus/mulai', $form + ['yakin' => '1']);
        $this->assertSame([['node-01', 'start_cycle']], $this->perintah->terkirim);
        $s = $this->pdo->query('SELECT * FROM cycles')->fetch();
        $this->assertSame(['node-01', 'rumput gajah', 'batch 1', null], [$s['device_id'], $s['bahan_pakan'], $s['catatan'], $s['selesai']]);
        $this->assertSame('2026-11-08 15:00:00', $s['mulai']);
        $this->assertEquals($this->id['operator'], $s['dibuat_oleh']);
        $l = $this->pdo->query('SELECT * FROM commands_log')->fetch();
        $this->assertSame(['start_cycle', 1], [$l['perintah'], (int) $l['terkirim']]);
        $this->assertEquals($this->id['operator'], $l['user_id']);

        // Drum yang siklusnya masih berjalan tidak bisa dimulai lagi.
        $this->kirim('/siklus/mulai', $form + ['yakin' => '1']);
        $this->assertStringContainsString('masih punya siklus yang berjalan', $this->kilat());
        $this->assertCount(1, $this->perintah->terkirim);
        $this->assertSame(1, (int) $this->nilai('SELECT COUNT(*) FROM cycles'));
    }

    public function testSiklusTidakDibuatBilaPerintahGagal(): void
    {
        $this->sebagai('admin');
        $this->perintah->gagal = true;
        $this->kirim('/siklus/mulai', ['device_id' => 'node-01', 'yakin' => '1']);
        $this->assertStringContainsString('Siklus tidak dimulai', $this->kilat());
        $this->assertSame(0, (int) $this->nilai('SELECT COUNT(*) FROM cycles'));
        $this->assertEquals(0, $this->nilai('SELECT terkirim FROM commands_log'));   // kegagalan tetap tercatat
    }

    public function testTutupSiklusDenganHasilAkhir(): void
    {
        $this->sebagai('operator');
        $this->kirim('/siklus/mulai', ['device_id' => 'node-01', 'yakin' => '1']);
        $id = (int) $this->nilai('SELECT id FROM cycles');
        $this->kini += 21 * 86400;
        $this->sebagai('operator');                                                  // masuk lagi, sesi lama sudah lewat 12 jam

        $tolak = [
            'Pilih hasil akhir: baik atau rusak.' => ['hasil' => 'lumayan'],
            'pH akhir harus angka antara 0 dan 14.' => ['hasil' => 'baik', 'ph_akhir' => '41'],
        ];
        foreach ($tolak as $pesan => $form) {
            $this->kirim("/siklus/$id/tutup", $form);
            $this->assertSame($pesan, $this->kilat());
        }
        $this->assertNull($this->nilai('SELECT selesai FROM cycles'));

        $this->kirim("/siklus/$id/tutup", ['hasil' => 'baik', 'ph_akhir' => '4,2', 'catatan_organoleptik' => 'hijau kecokelatan, aroma asam segar']);
        $s = $this->pdo->query('SELECT * FROM cycles')->fetch();
        $this->assertSame('baik', $s['hasil']);
        $this->assertEquals(4.2, $s['ph_akhir']);                                    // koma desimal diterima
        $this->assertSame('hijau kecokelatan, aroma asam segar', $s['catatan_organoleptik']);
        $this->assertSame('2026-11-29 15:00:00', $s['selesai']);

        $this->kirim("/siklus/$id/tutup", ['hasil' => 'rusak']);
        $this->assertStringContainsString('sudah ditutup', $this->kilat());
        $this->assertSame('baik', $this->nilai('SELECT hasil FROM cycles'));

        $t = $this->teks($this->minta('GET', '/siklus'));
        $this->assertMatchesRegularExpression('/Riwayat siklus .*node-01 .*Baik 4,20 hijau kecokelatan/', $t);
    }

    public function testPerintahButuhKonfirmasiDanSelaluTercatat(): void
    {
        $this->sebagai('operator');
        $this->kirim('/perintah', ['device_id' => 'node-01', 'perintah' => 'sample_now']);
        $this->assertStringContainsString('Centang konfirmasi', $this->kilat());
        $this->kirim('/perintah', ['device_id' => 'node-01', 'perintah' => 'start_cycle', 'yakin' => '1']);
        $this->assertSame('Perintah tidak dikenal.', $this->kilat());                // start_cycle hanya lewat Siklus
        $this->kirim('/perintah', ['device_id' => 'tidak-ada', 'perintah' => 'mute', 'yakin' => '1']);
        $this->assertSame([], $this->perintah->terkirim);
        $this->assertSame(0, (int) $this->nilai('SELECT COUNT(*) FROM commands_log'));

        $this->kirim('/perintah', ['device_id' => 'node-01', 'perintah' => 'sample_now', 'yakin' => '1']);
        $this->kirim('/perintah', ['device_id' => 'node-01', 'perintah' => 'mute', 'yakin' => '1']);
        $this->assertSame([['node-01', 'sample_now'], ['node-01', 'mute']], $this->perintah->terkirim);

        $this->perintah->gagal = true;
        $this->kirim('/perintah', ['device_id' => 'node-01', 'perintah' => 'mute', 'yakin' => '1']);
        $this->assertStringContainsString('gagal dikirim', $this->kilat());

        $this->assertSame(
            ['sample_now 1', 'mute 1', 'mute 0'],
            $this->pdo->query("SELECT perintah || ' ' || terkirim FROM commands_log ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)
        );
        $t = $this->teks($this->minta('GET', '/perintah'));
        $this->assertStringContainsString('Operator Uji node-01 Bisukan buzzer gagal', $t);
        $this->assertStringContainsString('Operator Uji node-01 Ukur sekarang terkirim', $t);
    }

    // ------------------------------------------------------------ ekspor

    public function testEksporCsvPerRentangTanggalWib(): void
    {
        $ingest = fn (int $ts, array $ubah = []) => (new Ingestor($this->pdo, fn (): int => $ts))
            ->terimaData('node-02', P::json('node-02', $ts, $ubah));
        $ingest(strtotime('2026-11-05 23:59:00 +07:00'));                            // di luar rentang
        $ingest(strtotime('2026-11-06 00:00:00 +07:00'), ['dT' => 1.25]);
        $ingest(strtotime('2026-11-07 12:00:00 +07:00'), ['null' => ['tengah', 'scd41']]);
        $ingest(strtotime('2026-11-07 23:59:59 +07:00'), ['reason' => '=HYPERLINK("http://jahat")']);
        $ingest(strtotime('2026-11-08 00:00:00 +07:00'));                            // di luar rentang

        $this->sebagai('peneliti');
        $r = $this->minta('GET', '/ekspor/unduh?device_id=node-02&dari=2026-11-06&sampai=2026-11-07');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $r->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="silase_node-02_20261106-20261107.csv"', $r->getHeaderLine('Content-Disposition'));

        $baris = array_map(fn ($b) => str_getcsv($b, ',', '"', ''), explode("\n", trim((string) $r->getBody())));
        $kepala = array_shift($baris);
        $this->assertSame(['waktu_wib', 'waktu_utc', 'device_id'], array_slice($kepala, 0, 3));
        $this->assertContains('t_tengah', $kepala);
        $this->assertContains('sensor_flags', $kepala);
        $this->assertCount(3, $baris);

        $data = array_map(fn ($b) => array_combine($kepala, $b), $baris);
        $this->assertSame('2026-11-06 00:00:00', $data[0]['waktu_wib']);
        $this->assertSame('2026-11-05 17:00:00', $data[0]['waktu_utc']);
        $this->assertSame('1.25', $data[0]['dt']);                                   // desimal titik
        $this->assertSame('', $data[1]['t_tengah']);                                 // null = sel kosong, bukan 0
        $this->assertSame('', $data[1]['co2_ppm']);
        $this->assertSame('18', $data[1]['sensor_flags']);
        $this->assertSame("'=HYPERLINK(\"http://jahat\")", $data[2]['reason']);      // tidak dijalankan sebagai rumus
    }

    public function testEksporPerSiklusDanMasukanSalah(): void
    {
        $this->sebagai('operator');
        $this->kirim('/siklus/mulai', ['device_id' => 'node-01', 'yakin' => '1']);
        $id = (int) $this->nilai('SELECT id FROM cycles');
        foreach ([300, 600] as $detik) {
            (new Ingestor($this->pdo, fn (): int => self::KINI + $detik))
                ->terimaData('node-01', P::json('node-01', self::KINI + $detik));
        }
        $this->kini += 900;

        $this->sebagai('peneliti');
        $r = $this->minta('GET', "/ekspor/unduh?siklus=$id");
        $this->assertSame('attachment; filename="silase_node-01_siklus-' . $id . '.csv"', $r->getHeaderLine('Content-Disposition'));
        $this->assertCount(3, explode("\n", trim((string) $r->getBody())));          // kepala + 2 pembacaan sejak siklus mulai

        foreach (['siklus=999', 'device_id=node-01&dari=2026-11-09&sampai=2026-11-01', 'device_id=x&dari=2026-11-01&sampai=2026-11-02', 'device_id=node-01&dari=kemarin&sampai=2026-11-02'] as $kueri) {
            $r = $this->minta('GET', "/ekspor/unduh?$kueri");
            $this->assertSame(303, $r->getStatusCode(), $kueri);
            $this->assertSame('/ekspor', $r->getHeaderLine('Location'));
        }
    }
}
