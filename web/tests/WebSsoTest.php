<?php

declare(strict_types=1);

namespace Silase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Silase\Db;
use Silase\Masuk\GoogleSso;
use Silase\Notifikasi\HttpGagal;
use Silase\Pengaturan;
use Silase\Pengguna\PenggunaRepo;
use Silase\Web\Aplikasi;
use Silase\Web\Sesi;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Login dengan akun Google (jawaban Google ditiru). */
final class WebSsoTest extends TestCase
{
    private const KINI = 1794150000;
    private const ASAL = 'https://silase.ir1lab.id';
    private const KLIEN = 'klien-123.apps.googleusercontent.com';

    private PDO $pdo;
    private Sesi $sesi;
    private PenggunaRepo $pengguna;
    private HttpTiruan $http;

    protected function setUp(): void
    {
        $this->pdo = Db::sqlite(':memory:', dirname(__DIR__) . '/sql/schema.sql');
        $this->sesi = Sesi::memori();
        $this->http = new HttpTiruan([]);
        $this->pengguna = new PenggunaRepo($this->pdo, new Pengaturan($this->pdo));
        $this->pengguna->buat('Irwan', 'irwan@gmail.com', 'admin', null, self::KINI);
    }

    private function minta(string $metode, string $url, array $form = []): ResponseInterface
    {
        $sso = new GoogleSso($this->http, self::KLIEN, 'rahasia-klien', self::ASAL . '/');
        $app = Aplikasi::buat($this->pdo, fn (): int => self::KINI, false, null, $this->sesi, null, $sso);
        $req = (new ServerRequestFactory())->createServerRequest($metode, $url)->withParsedBody($form);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $kueri);
        return $app->handle($req->withQueryParams($kueri));
    }

    /** Memulai alur dan mengembalikan [state, nonce] yang dikirim ke Google. */
    private function mulai(): array
    {
        $r = $this->minta('GET', self::ASAL . '/masuk/google');
        $this->assertSame(303, $r->getStatusCode());
        parse_str((string) parse_url($r->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        return [$q['state'], $q['nonce'], $q, $r->getHeaderLine('Location')];
    }

    /** Menyiapkan jawaban token Google dengan id_token berisi klaim tertentu. */
    private function jawabGoogle(string $nonce, array $ubah = [], int $status = 200): void
    {
        $klaim = $ubah + [
            'iss' => 'https://accounts.google.com', 'aud' => self::KLIEN, 'exp' => self::KINI + 3600,
            'nonce' => $nonce, 'email' => 'Irwan@gmail.com', 'email_verified' => true, 'name' => 'Irwan N',
        ];
        $b64 = fn (array $d): string => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $this->http = new HttpTiruan([[$status, json_encode(['id_token' => $b64(['alg' => 'RS256']) . '.' . $b64($klaim) . '.tandatangan'])]]);
    }

    private function balik(string $state, string $kode = 'kode-dari-google'): ResponseInterface
    {
        return $this->minta('GET', self::ASAL . '/masuk/google/balik?state=' . $state . '&code=' . $kode);
    }

    private function kilat(): string
    {
        return $this->sesi->ambilKilat()['pesan'] ?? '';
    }

    public function testHalamanMasukHanyaMenawarkanGoogle(): void
    {
        $html = (string) $this->minta('GET', self::ASAL . '/masuk')->getBody();
        $this->assertStringContainsString('href="/masuk/google"', $html);
        $this->assertStringContainsString('Masuk dengan Google', $html);
        $this->assertStringNotContainsString('type="password"', $html);

        // Login kata sandi dimatikan selama login Google aktif.
        $r = $this->minta('POST', self::ASAL . '/masuk', ['email' => 'irwan@gmail.com', 'sandi' => 'apa-saja-boleh', 'csrf' => $this->sesi->token()]);
        $this->assertSame(403, $r->getStatusCode());
    }

    public function testDiarahkanKeGoogleDenganStateDanNonce(): void
    {
        [$state, $nonce, $q, $lokasi] = $this->mulai();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $lokasi);
        $this->assertSame(self::KLIEN, $q['client_id']);
        $this->assertSame(self::ASAL . '/masuk/google/balik', $q['redirect_uri']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame(32, strlen($state));
        $this->assertSame(32, strlen($nonce));
        $this->assertNotSame($state, $nonce);

        // Dibuka dari alamat lain (mis. IP lokal): dipindahkan ke alamat resmi dulu.
        $r = $this->minta('GET', 'http://192.168.110.202:8088/masuk/google');
        $this->assertSame(self::ASAL . '/masuk', $r->getHeaderLine('Location'));
    }

    public function testMasukDenganAkunGoogleYangTerdaftar(): void
    {
        [$state, $nonce] = $this->mulai();
        $this->jawabGoogle($nonce);
        $r = $this->balik($state);
        $this->assertSame('/', $r->getHeaderLine('Location'));

        // Kode ditukar ke Google dengan rahasia klien dan alamat balik yang sama.
        [$metode, $url, , $badan] = $this->http->permintaan[0];
        parse_str($badan, $kirim);
        $this->assertSame(['POST', 'https://oauth2.googleapis.com/token'], [$metode, $url]);
        $this->assertSame(
            ['code' => 'kode-dari-google', 'client_id' => self::KLIEN, 'client_secret' => 'rahasia-klien', 'redirect_uri' => self::ASAL . '/masuk/google/balik', 'grant_type' => 'authorization_code'],
            $kirim
        );

        $t = preg_replace('/\s+/', ' ', strip_tags((string) $this->minta('GET', self::ASAL . '/')->getBody()));
        $this->assertStringContainsString('Irwan · Admin', $t);              // email Google dicocokkan tanpa peduli huruf besar
    }

    public function testAkunGoogleYangTidakTerdaftarDitolak(): void
    {
        [$state, $nonce] = $this->mulai();
        $this->jawabGoogle($nonce, ['email' => 'orang.lain@gmail.com']);
        $this->assertSame('/masuk', $this->balik($state)->getHeaderLine('Location'));
        $this->assertStringContainsString('orang.lain@gmail.com belum terdaftar', $this->kilat());
        $this->assertSame('/masuk', $this->minta('GET', self::ASAL . '/')->getHeaderLine('Location'));

        // Pengguna terdaftar tetapi dinonaktifkan juga ditolak.
        $this->pdo->exec('UPDATE users SET aktif = 0');
        [$state, $nonce] = $this->mulai();
        $this->jawabGoogle($nonce);
        $this->balik($state);
        $this->assertStringContainsString('belum terdaftar', $this->kilat());
    }

    public function testStateSalahAtauDipakaiUlangDitolak(): void
    {
        [$state, $nonce] = $this->mulai();
        $this->jawabGoogle($nonce);
        $this->assertSame('/masuk', $this->balik('state-palsu')->getHeaderLine('Location'));
        $this->assertStringContainsString('kedaluwarsa', $this->kilat());
        $this->assertSame([], $this->http->permintaan);                     // Google tidak dihubungi sama sekali

        // State hanya berlaku sekali: setelah percobaan di atas, state asli pun tidak diterima.
        $this->assertSame('/masuk', $this->balik($state)->getHeaderLine('Location'));

        // Pengguna menekan "batal" di halaman Google.
        $this->mulai();
        $r = $this->minta('GET', self::ASAL . '/masuk/google/balik?error=access_denied&state=x');
        $this->assertSame('/masuk', $r->getHeaderLine('Location'));
        $this->assertSame('Masuk dengan Google dibatalkan.', $this->kilat());
    }

    public function testKlaimTokenDiperiksa(): void
    {
        $kasus = [
            'tujuan lain' => [['aud' => 'aplikasi-lain.apps.googleusercontent.com'], 'tidak valid'],
            'penerbit lain' => [['iss' => 'https://accounts.jahat.example'], 'tidak valid'],
            'kedaluwarsa' => [['exp' => self::KINI - 1], 'tidak valid'],
            'nonce beda' => [['nonce' => 'nonce-lain'], 'tidak valid'],
            'email belum terverifikasi' => [['email_verified' => false], 'belum terverifikasi'],
        ];
        foreach ($kasus as $nama => [$ubah, $pesan]) {
            [$state, $nonce] = $this->mulai();
            $this->jawabGoogle($nonce, $ubah);
            $this->assertSame('/masuk', $this->balik($state)->getHeaderLine('Location'), $nama);
            $this->assertStringContainsString($pesan, $this->kilat(), $nama);
        }
        $this->assertSame('/masuk', $this->minta('GET', self::ASAL . '/')->getHeaderLine('Location'));
    }

    public function testGoogleMenolakAtauTidakTerjangkau(): void
    {
        [$state, $nonce] = $this->mulai();
        $this->jawabGoogle($nonce, [], 400);
        $this->balik($state);
        $this->assertStringContainsString('Google menolak permintaan masuk', $this->kilat());

        [$state] = $this->mulai();
        $this->http = new HttpTiruan([new HttpGagal('timeout')]);
        $this->balik($state);
        $this->assertStringContainsString('Google tidak bisa dihubungi', $this->kilat());
    }

    public function testPenggunaDidaftarkanTanpaKataSandi(): void
    {
        [$state, $nonce] = $this->mulai();
        $this->jawabGoogle($nonce);
        $this->balik($state);
        $csrf = $this->sesi->token();

        $this->minta('POST', self::ASAL . '/pengguna', ['nama' => 'Bu Sari', 'email' => 'Sari@Gmail.com', 'peran' => 'operator', 'csrf' => $csrf]);
        $this->assertStringContainsString('bisa langsung masuk dengan akun Google', $this->kilat());
        $u = $this->pengguna->cariEmail('sari@gmail.com');
        $this->assertSame('operator', $u['peran']);
        $this->assertStringStartsWith('!', $u['password_hash']);            // tidak ada kata sandi yang bisa cocok
        $this->assertIsString($this->pengguna->masuk('sari@gmail.com', '', self::KINI));

        $html = (string) $this->minta('GET', self::ASAL . '/pengguna')->getBody();
        $this->assertStringNotContainsString('type="password"', $html);
        $this->assertStringNotContainsString('type="password"', (string) $this->minta('GET', self::ASAL . '/akun')->getBody());

        // Tindakan kata sandi ditolak selama login Google aktif.
        $this->minta('POST', self::ASAL . '/pengguna/' . $u['id'] . '/sandi', ['sandi' => 'sandi-baru-panjang', 'csrf' => $csrf]);
        $this->assertStringContainsString('Login memakai akun Google', $this->kilat());
        $this->assertStringStartsWith('!', $this->pengguna->cariEmail('sari@gmail.com')['password_hash']);
    }
}
