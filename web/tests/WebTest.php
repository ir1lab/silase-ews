<?php

declare(strict_types=1);

namespace Silase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Silase\Db;
use Silase\Drum\Deret;
use Silase\Ingest\Ingestor;
use Silase\Mqtt;
use Silase\Simulasi\PembuatPayload as P;
use Silase\Web\Aplikasi;
use Silase\Web\Format;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Uji halaman ringkasan, detail drum, dan data grafik dengan SQLite di memori. */
final class WebTest extends TestCase
{
    private const KINI = 1794150000;

    private PDO $pdo;
    private int $kini = self::KINI;

    protected function setUp(): void
    {
        $this->pdo = Db::sqlite(':memory:', dirname(__DIR__) . '/sql/schema.sql');
    }

    /** Menyimpan satu pembacaan seolah diterima $umur detik yang lalu. */
    private function baca(string $device, int $umur, array $ubah = []): void
    {
        $saat = self::KINI - $umur;
        (new Ingestor($this->pdo, fn (): int => $saat))->terimaData($device, P::json($device, $saat, $ubah));
    }

    private function kirimStatus(string $device, string $json): void
    {
        (new Ingestor($this->pdo))->terimaStatus($device, $json);
    }

    private function get(string $url): ResponseInterface
    {
        $app = Aplikasi::buat($this->pdo, fn (): int => $this->kini);
        return $app->handle((new ServerRequestFactory())->createServerRequest('GET', $url));
    }

    private function teks(ResponseInterface $r): string
    {
        return preg_replace('/\s+/', ' ', strip_tags((string) $r->getBody()));
    }

    public function testRingkasanKosong(): void
    {
        $r = $this->get('/');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('Belum ada drum yang mengirim data', $this->teks($r));
    }

    public function testRingkasanMenampilkanKondisiTiapDrum(): void
    {
        $this->baca('drum-normal', 60);
        $this->baca('drum-bahaya', 120, ['dT' => 5.4, 'cycle_h' => 123.0]);
        $this->baca('drum-waspada', 30, ['dT' => 2.6]);
        $this->baca('drum-basi', 16 * 60);                       // lebih tua dari 15 menit
        $this->baca('drum-mati', 300);
        $this->kirimStatus('drum-mati', '{"online":false}');          // last will dari broker

        $r = $this->get('/');
        $this->assertSame(200, $r->getStatusCode());
        $t = $this->teks($r);

        $this->assertStringContainsString('4 dari 5 drum perlu diperiksa', $t);   // semua kecuali yang normal
        $this->assertMatchesRegularExpression('/drum-bahaya drum-bahaya Bahaya 5,4 °C di atas ambien/', $t);
        $this->assertMatchesRegularExpression('/drum-basi drum-basi Tidak ada data/', $t);
        $this->assertMatchesRegularExpression('/drum-mati drum-mati Offline/', $t);
        $this->assertMatchesRegularExpression('/drum-normal drum-normal Normal 0,9/', $t);
        $this->assertStringContainsString('8.450 ppm', $t);
        $this->assertStringContainsString('5 hari 3 jam', $t);                     // cycle_h 123
        $this->assertStringContainsString('Data terakhir 16 menit lalu', $t);
        $this->assertStringContainsString('8 Nov 2026 21.59 WIB', $t);             // 14.59 UTC

        // Yang perlu perhatian tampil lebih dulu.
        $urut = array_map(fn ($n) => strpos($t, "drum-$n drum-$n"), ['bahaya', 'waspada', 'basi', 'mati', 'normal']);
        $this->assertSame($urut, array_values(array_filter($urut, 'is_int')));
        $terurut = $urut;
        sort($terurut);
        $this->assertSame($terurut, $urut);
    }

    public function testDataBaruMembuatDrumOnlineLagi(): void
    {
        $this->kirimStatus('node-01', '{"online":false}');
        $this->baca('node-01', 20);
        $this->assertMatchesRegularExpression('/node-01 node-01 Normal/', $this->teks($this->get('/')));
    }

    public function testDetailDrum(): void
    {
        $this->baca('node-01', 40, ['null' => ['tengah', 'scd41'], 'dT' => 2.6]);

        $r = $this->get('/drum/node-01?rentang=7h');
        $this->assertSame(200, $r->getStatusCode());
        $html = (string) $r->getBody();
        $t = $this->teks($r);

        $this->assertStringContainsString('Sensor gagal: Probe tengah, SCD41 (CO₂ dan RH)', $t);
        $this->assertStringContainsString('titik terpanas: lapis atas', $t);
        $this->assertStringContainsString('WASPADA kenaikan suhu internal', $t);     // riwayat peringatan drum
        $this->assertStringContainsString('masih berlangsung', $t);
        $this->assertMatchesRegularExpression('/Tengah\s+–\s*°C/', $t);            // null tampil sebagai tanda pisah
        $this->assertStringContainsString('data-url="/api/drum/node-01/deret?rentang=7h"', $html);
        $this->assertStringNotContainsString('<script>', $html);                    // CSP: tanpa skrip sebaris
    }

    public function testDetailDrumTidakDikenal(): void
    {
        $this->assertSame(404, $this->get('/drum/tidak-ada')->getStatusCode());
        $this->assertSame(404, $this->get('/api/drum/tidak-ada/deret')->getStatusCode());
        $this->baca('node-01', 10);
        $this->assertSame(404, $this->get('/api/drum/node-01/deret?rentang=1abad')->getStatusCode());
    }

    public function testIsiDrumDiEscape(): void
    {
        $this->baca('node-01', 10);
        $this->pdo->exec("UPDATE devices SET nama = '<script>alert(1)</script>' WHERE device_id = 'node-01'");
        foreach (['/', '/drum/node-01'] as $url) {
            $html = (string) $this->get($url)->getBody();
            $this->assertStringNotContainsString('<script>alert', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
        }
    }

    public function testKepalaKeamanan(): void
    {
        $r = $this->get('/');
        $this->assertSame('nosniff', $r->getHeaderLine('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'self'", $r->getHeaderLine('Content-Security-Policy'));
    }

    public function testDeretMenjagaNullDanMembatasiRentang(): void
    {
        $this->baca('node-01', 900);
        $this->baca('node-01', 600, ['null' => ['tengah', 'scd41']]);
        $this->baca('node-01', 300);
        $this->baca('node-01', 2 * 86400);                        // di luar 24 jam

        $r = $this->get('/api/drum/node-01/deret?rentang=24j');
        $this->assertSame(200, $r->getStatusCode());
        $d = json_decode((string) $r->getBody(), true);

        $this->assertCount(3, $d['t']);
        $this->assertSame((self::KINI - 900) * 1000, $d['t'][0]);
        $this->assertSame([29.4, null, 29.4], $d['tengah']);      // null tetap null, bukan 0
        $this->assertSame([8450, null, 8450], $d['co2']);
        $this->assertSame([96.2, null, 96.2], $d['rh']);
        $this->assertSame(300, $d['selang_s']);
        $this->assertSame((self::KINI - 86400) * 1000, $d['dari']);

        $d7 = json_decode((string) $this->get('/api/drum/node-01/deret?rentang=7h')->getBody(), true);
        $this->assertCount(4, $d7['t']);
    }

    public function testRentangSiklusDihitungDariUmurSiklusFirmware(): void
    {
        $this->baca('node-01', 10 * 86400, ['cycle_h' => 0.0]);
        $this->baca('node-01', 9 * 86400, ['cycle_h' => 24.0]);
        $this->baca('node-01', 20 * 86400, ['cycle_h' => 700.0]); // siklus sebelumnya
        $this->baca('node-01', 60, ['cycle_h' => 240.0]);

        $d = json_decode((string) $this->get('/api/drum/node-01/deret?rentang=siklus')->getBody(), true);
        $this->assertCount(3, $d['t']);
        $this->assertGreaterThan(300, $d['selang_s']);            // 10 hari dirata-ratakan per selang
    }

    public function testDeretRataRataMengabaikanNull(): void
    {
        $baris = [];
        foreach ([[0, '30.00'], [300, null], [600, '32.00']] as [$dt, $v]) {
            $baris[] = [
                'measured_at' => gmdate('Y-m-d H:i:s', 1794000000 + $dt), 'ts_valid' => 1,
                't_bawah' => $v, 't_tengah' => null, 't_atas' => $v, 't_ambien' => $v,
                'dt' => $v, 'co2_ppm' => $v, 'hs_rh' => $v,
            ];
        }
        // Rentang 30 hari -> selang 25 menit; ketiga baris jatuh di satu selang.
        $d = Deret::susun($baris, 1794000000 - 30 * 86400, 1794000000 + 900);
        $this->assertSame(1500, $d['selang_s']);
        $this->assertSame([31.0], $d['bawah']);
        $this->assertSame([null], $d['tengah']);
    }

    public function testFormat(): void
    {
        $this->assertSame('8 Nov 2026 22.00 WIB', Format::wib('2026-11-08 15:00:00'));
        $this->assertSame('–', Format::angka(null));
        $this->assertSame('8.450', Format::angka('8450.00', 0));
        $this->assertSame('5,4', Format::angka(5.42));
        $this->assertSame('kurang dari 1 jam', Format::umur('0.50'));
        $this->assertSame('3 hari', Format::umur(72));
        $this->assertSame('2 jam lalu', Format::lalu(7300));
    }

    public function testUraiUrlMqtt(): void
    {
        $this->assertSame(
            ['host' => 'mqtt.ir1lab.id', 'port' => 443, 'tls' => true, 'ws' => true, 'jalur' => '/'],
            Mqtt::uraiUrl('wss://mqtt.ir1lab.id:443')
        );
        $this->assertSame(
            ['host' => 'mqtt.ir1lab.id', 'port' => 443, 'tls' => true, 'ws' => true, 'jalur' => '/mqtt'],
            Mqtt::uraiUrl('wss://mqtt.ir1lab.id/mqtt')
        );
        $this->assertSame(
            ['host' => '127.0.0.1', 'port' => 1883, 'tls' => false, 'ws' => false, 'jalur' => '/'],
            Mqtt::uraiUrl('mqtt://127.0.0.1')
        );
        $this->expectException(\RuntimeException::class);
        Mqtt::uraiUrl('mqtt.ir1lab.id:443');
    }
}
