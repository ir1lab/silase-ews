<?php

declare(strict_types=1);

namespace Silase\Web;

use PDO;
use Psr\Http\Message\ResponseInterface as Respons;
use Psr\Http\Message\ServerRequestInterface as Permintaan;
use Psr\Http\Server\RequestHandlerInterface as Penangan;
use Silase\Drum\Deret;
use Silase\Drum\DrumRepo;
use Silase\Drum\Kondisi;
use Slim\App;
use Slim\Factory\AppFactory;

final class Aplikasi
{
    /** @param (\Closure(): int)|null $jam sumber waktu epoch, diganti pada uji */
    public static function buat(PDO $pdo, ?\Closure $jam = null, bool $debug = false): App
    {
        $app = AppFactory::create();
        $repo = new DrumRepo($pdo);
        $tampilan = new Tampilan(dirname(__DIR__, 2) . '/templates');
        $kini = fn (): int => $jam ? $jam() : time();

        // --- Ringkasan: satu kartu per drum, yang perlu perhatian paling atas
        $app->get('/', function (Permintaan $req, Respons $res) use ($repo, $tampilan, $kini) {
            $saat = $kini();
            $terakhir = $repo->terakhirSemua();
            $kartu = [];
            foreach ($repo->semua() as $d) {
                $t = $terakhir[$d['device_id']] ?? null;
                $kartu[] = ['device' => $d, 'terakhir' => $t, 'kondisi' => Kondisi::dari($d, $t, $saat)];
            }
            usort($kartu, fn ($a, $b) => [$a['kondisi']->urutan, $a['device']['nama']]
                <=> [$b['kondisi']->urutan, $b['device']['nama']]);

            return $tampilan->halaman($res, 'ringkasan', [
                'judul' => 'Ringkasan',
                'kartu' => $kartu,
                'segarkan' => 60,
            ]);
        });

        // --- Detail drum
        $app->get('/drum/{id}', function (Permintaan $req, Respons $res, array $arg) use ($repo, $tampilan, $kini) {
            $device = $repo->satu($arg['id']);
            if ($device === null) {
                return $tampilan->halaman($res, 'tidak-ditemukan', ['judul' => 'Drum tidak ditemukan'], 404);
            }
            $rentang = $req->getQueryParams()['rentang'] ?? '24j';
            if (!isset(Deret::RENTANG[$rentang])) {
                $rentang = '24j';
            }
            $terakhir = $repo->terakhir($device['device_id']);

            return $tampilan->halaman($res, 'drum', [
                'judul' => $device['nama'],
                'device' => $device,
                'terakhir' => $terakhir,
                'kondisi' => Kondisi::dari($device, $terakhir, $kini()),
                'sensorGagal' => Kondisi::sensorGagal((int) ($terakhir['sensor_flags'] ?? 0)),
                'rentang' => $rentang,
                'peringatan' => $repo->peringatan($device['device_id']),
                'skrip' => ['/assets/chart.umd.js', '/assets/drum.js'],
            ]);
        });

        // --- Data grafik
        $app->get('/api/drum/{id}/deret', function (Permintaan $req, Respons $res, array $arg) use ($repo, $kini) {
            $device = $repo->satu($arg['id']);
            $rentang = $req->getQueryParams()['rentang'] ?? '24j';
            if ($device === null || !isset(Deret::RENTANG[$rentang])) {
                $res->getBody()->write('{"galat":"tidak ditemukan"}');
                return $res->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
            $saat = $kini();
            $dari = Deret::awal($rentang, $repo->terakhir($device['device_id']), $saat);
            $data = Deret::susun($repo->deret($device['device_id'], $dari, $saat), $dari, $saat);

            $res->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
            return $res->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
        });

        $app->addRoutingMiddleware();
        $app->addErrorMiddleware($debug, true, true);
        // Ditambahkan terakhir agar membungkus semuanya, termasuk halaman galat.
        $app->add(function (Permintaan $req, Penangan $lanjut): Respons {
            return $lanjut->handle($req)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('X-Frame-Options', 'DENY')
                ->withHeader('Referrer-Policy', 'same-origin')
                ->withHeader(
                    'Content-Security-Policy',
                    "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
                );
        });

        return $app;
    }
}
