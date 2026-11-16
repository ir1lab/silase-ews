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
use Silase\Notifikasi\Antrean;
use Silase\Notifikasi\PabrikNotifier;
use Silase\Notifikasi\PenerimaRepo;
use Silase\Notifikasi\Pengirim;
use Silase\Notifikasi\PunyaQr;
use Silase\Notifikasi\WhatsAppNotifier;
use Silase\Pengaturan;
use Silase\Peringatan\AlertRepo;
use Slim\App;
use Slim\Factory\AppFactory;

final class Aplikasi
{
    /**
     * @param (\Closure(): int)|null $jam sumber waktu epoch, diganti pada uji
     * @param WhatsAppNotifier|null $wa null bila WhatsApp belum dikonfigurasi
     */
    public static function buat(
        PDO $pdo,
        ?\Closure $jam = null,
        bool $debug = false,
        ?WhatsAppNotifier $wa = null,
        ?Sesi $sesi = null,
    ): App {
        $app = AppFactory::create();
        $sesi ??= Sesi::php();
        $repo = new DrumRepo($pdo);
        $alert = new AlertRepo($pdo);
        $penerima = new PenerimaRepo($pdo);
        $pengaturan = new Pengaturan($pdo);
        $kini = fn (): int => $jam ? $jam() : time();

        // Data kerangka: lencana jumlah peringatan, banner WhatsApp, pesan sekali tampil.
        $tampilan = new Tampilan(dirname(__DIR__, 2) . '/templates', function () use ($alert, $pengaturan, $wa, $sesi) {
            $status = $pengaturan->ambilJson(Pengirim::KUNCI_STATUS);
            return [
                'jumlahAktif' => $alert->jumlahAktif(),
                'waTerputus' => $wa !== null && $status !== null && empty($status['ok']),
                'kilat' => $sesi->ambilKilat(),
            ];
        });

        $alihkan = fn (Respons $res, string $ke): Respons => $res->withStatus(303)->withHeader('Location', $ke);

        // Semua form wajib membawa token CSRF yang cocok dengan sesi pengunjung.
        $csrf = function (Permintaan $req, Penangan $lanjut) use ($sesi, $tampilan, $app): Respons {
            if (!$sesi->cocok(((array) $req->getParsedBody())['csrf'] ?? null)) {
                return $tampilan->halaman($app->getResponseFactory()->createResponse(), 'galat', [
                    'judul' => 'Form kedaluwarsa',
                    'pesan' => 'Form ini sudah kedaluwarsa. Kembali ke halaman sebelumnya, muat ulang, lalu coba lagi.',
                ], 403);
            }
            return $lanjut->handle($req);
        };

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
                'menu' => 'ringkasan',
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
                'menu' => 'ringkasan',
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

        // --- Peringatan: daftar aktif, riwayat, dan tombol "akui"
        $app->get('/peringatan', function (Permintaan $req, Respons $res) use ($alert, $tampilan, $sesi) {
            return $tampilan->halaman($res, 'peringatan', [
                'judul' => 'Peringatan',
                'menu' => 'peringatan',
                'aktif' => $alert->aktif(),
                'riwayat' => $alert->riwayat(),
                'csrf' => $sesi->token(),
                'segarkan' => 60,
            ]);
        });

        $app->post('/peringatan/{id:[0-9]+}/akui', function (Permintaan $req, Respons $res, array $arg) use ($alert, $sesi, $kini, $alihkan) {
            $alert->akui((int) $arg['id'], $kini())
                ? $sesi->kilat('Peringatan diakui. Pengingat WhatsApp untuk peringatan ini dihentikan.')
                : $sesi->kilat('Peringatan itu sudah diakui atau tidak ditemukan.', true);
            return $alihkan($res, '/peringatan');
        })->add($csrf);

        // --- Pengaturan WhatsApp: status gateway, kode QR, penerima per drum, pesan uji
        $app->get('/whatsapp', function (Permintaan $req, Respons $res) use ($repo, $penerima, $pengaturan, $tampilan, $sesi, $wa, $kini) {
            $status = null;
            if ($wa !== null) {
                $status = $wa->status();
                if (!$status['ok'] && $wa instanceof PunyaQr) {
                    $wa->siapkan();                       // supaya kode QR tersedia untuk dipindai
                }
                if ($status['ok']) {
                    // Banner "terputus" langsung hilang tanpa menunggu cek berkala worker.
                    $pengaturan->simpanJson(Pengirim::KUNCI_STATUS, $status + ['dicek' => $kini()], $kini());
                }
            }
            return $tampilan->halaman($res, 'whatsapp', [
                'judul' => 'WhatsApp',
                'menu' => 'whatsapp',
                'provider' => $wa?->nama(),
                'status' => $status,
                // Kode QR hanya ada bila gateway terjangkau dan sesinya belum tertaut.
                'adaQr' => $wa instanceof PunyaQr && $status !== null && !$status['ok']
                    && !in_array($status['status'], ['TIDAK_TERJANGKAU', 'KUNCI_DITOLAK'], true),
                'drum' => $repo->semua(),
                'penerima' => $penerima->semua(),
                'antrean' => $penerima->antreanTerakhir(),
                'csrf' => $sesi->token(),
                'waTerputus' => $status !== null && !$status['ok'],
                'segarkan' => ($status !== null && !$status['ok']) ? 30 : null,
            ]);
        });

        $app->get('/whatsapp/qr.png', function (Permintaan $req, Respons $res) use ($wa) {
            $png = $wa instanceof PunyaQr ? $wa->qr() : null;
            if ($png === null) {
                return $res->withStatus(404);
            }
            $res->getBody()->write($png);
            return $res->withHeader('Content-Type', 'image/png')->withHeader('Cache-Control', 'no-store');
        });

        $app->post('/whatsapp/penerima', function (Permintaan $req, Respons $res) use ($repo, $penerima, $sesi, $alihkan) {
            $f = (array) $req->getParsedBody();
            $nama = trim((string) ($f['nama'] ?? ''));
            $nomor = PenerimaRepo::normalNomor((string) ($f['nomor'] ?? ''));
            $device = $repo->satu((string) ($f['device_id'] ?? ''));

            if ($device === null) {
                $sesi->kilat('Pilih drum yang terdaftar.', true);
            } elseif ($nama === '' || mb_strlen($nama) > 100) {
                $sesi->kilat('Nama penerima wajib diisi (paling banyak 100 huruf).', true);
            } elseif ($nomor === null) {
                $sesi->kilat('Nomor WhatsApp tidak valid. Pakai nomor seluler Indonesia, misalnya 081234567890.', true);
            } elseif (!$penerima->tambah($device['device_id'], $nama, $nomor)) {
                $sesi->kilat('Nomor itu sudah terdaftar untuk drum tersebut.', true);
            } else {
                $sesi->kilat("$nama ditambahkan sebagai penerima peringatan {$device['nama']}.");
            }
            return $alihkan($res, '/whatsapp');
        })->add($csrf);

        $app->post('/whatsapp/penerima/{id:[0-9]+}/aktif', function (Permintaan $req, Respons $res, array $arg) use ($penerima, $alihkan) {
            $penerima->balikAktif((int) $arg['id']);
            return $alihkan($res, '/whatsapp');
        })->add($csrf);

        $app->post('/whatsapp/penerima/{id:[0-9]+}/hapus', function (Permintaan $req, Respons $res, array $arg) use ($penerima, $sesi, $alihkan) {
            $penerima->hapus((int) $arg['id']);
            $sesi->kilat('Penerima dihapus.');
            return $alihkan($res, '/whatsapp');
        })->add($csrf);

        // Pesan uji hanya ke nomor yang terdaftar sebagai penerima.
        $app->post('/whatsapp/uji', function (Permintaan $req, Respons $res) use ($pdo, $penerima, $sesi, $kini, $alihkan) {
            $p = $penerima->satu((int) (((array) $req->getParsedBody())['penerima_id'] ?? 0));
            if ($p === null) {
                $sesi->kilat('Pilih penerima untuk pesan uji.', true);
            } else {
                (new Antrean($pdo, PabrikNotifier::namaProvider()))->uji($p['nomor'], $kini());
                $sesi->kilat("Pesan uji untuk {$p['nama']} masuk antrean. Lihat statusnya di tabel antrean di bawah.");
            }
            return $alihkan($res, '/whatsapp');
        })->add($csrf);

        $app->addBodyParsingMiddleware();
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
                    "default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'"
                );
        });

        return $app;
    }
}
