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
use Silase\Ekspor\Csv;
use Silase\Masuk\GoogleSso;
use Silase\Notifikasi\Antrean;
use Silase\Notifikasi\PabrikNotifier;
use Silase\Notifikasi\PenerimaRepo;
use Silase\Notifikasi\Pengirim;
use Silase\Notifikasi\PunyaQr;
use Silase\Notifikasi\WhatsAppNotifier;
use Silase\Pengaturan;
use Silase\Pengguna\PenggunaRepo;
use Silase\Perintah\PengirimPerintah;
use Silase\Perintah\PerintahRepo;
use Silase\Peringatan\AlertRepo;
use Silase\Siklus\SiklusRepo;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Stream;

/**
 * Rute dan hak akses.
 *
 *   semua peran      : ringkasan, detail drum, peringatan (lihat), siklus (lihat), ekspor, akun
 *   admin, operator  : akui peringatan, ubah drum, mulai/tutup siklus, perintah
 *   admin            : pengaturan WhatsApp, kelola pengguna
 */
final class Aplikasi
{
    private const PENGELOLA = ['admin', 'operator'];
    private const ADMIN = ['admin'];

    /**
     * @param (\Closure(): int)|null $jam sumber waktu epoch, diganti pada uji
     * @param WhatsAppNotifier|null $wa null bila WhatsApp belum dikonfigurasi
     * @param PengirimPerintah|null $perintah null bila broker belum dikonfigurasi
     * @param GoogleSso|null $sso bila diisi, login hanya lewat akun Google; null = login kata sandi
     */
    public static function buat(
        PDO $pdo,
        ?\Closure $jam = null,
        bool $debug = false,
        ?WhatsAppNotifier $wa = null,
        ?Sesi $sesi = null,
        ?PengirimPerintah $perintah = null,
        ?GoogleSso $sso = null,
    ): App {
        $app = AppFactory::create();
        $sesi ??= Sesi::php();
        $repo = new DrumRepo($pdo);
        $alert = new AlertRepo($pdo);
        $penerima = new PenerimaRepo($pdo);
        $pengaturan = new Pengaturan($pdo);
        $pengguna = new PenggunaRepo($pdo, $pengaturan);
        $siklus = new SiklusRepo($pdo);
        $log = new PerintahRepo($pdo);
        $kini = fn (): int => $jam ? $jam() : time();
        $pemakai = null;                       // pengguna yang sedang masuk, diisi penjaga rute

        // Data kerangka: menu sesuai peran, lencana peringatan, banner WhatsApp, pesan sekali tampil.
        $tampilan = new Tampilan(dirname(__DIR__, 2) . '/templates', function () use (&$pemakai, $alert, $pengaturan, $wa, $sesi, $sso) {
            if ($pemakai === null) {
                return ['pemakai' => null, 'sso' => $sso !== null, 'kilat' => $sesi->ambilKilat()];
            }
            $status = $pengaturan->ambilJson(Pengirim::KUNCI_STATUS);
            return [
                'pemakai' => $pemakai,
                'sso' => $sso !== null,
                'csrf' => $sesi->token(),
                'jumlahAktif' => $alert->jumlahAktif(),
                'waTerputus' => $wa !== null && $status !== null && empty($status['ok']),
                'kilat' => $sesi->ambilKilat(),
            ];
        });

        $alihkan = fn (Respons $res, string $ke): Respons => $res->withStatus(303)->withHeader('Location', $ke);
        $form = fn (Permintaan $req): array => (array) $req->getParsedBody();
        $teks = fn (array $f, string $kunci, int $maks): ?string
            => ($v = mb_substr(trim((string) ($f[$kunci] ?? '')), 0, $maks)) === '' ? null : $v;
        $tolak = fn (string $judul, string $pesan): Respons
            => $tampilan->halaman($app->getResponseFactory()->createResponse(), 'galat', ['judul' => $judul, 'pesan' => $pesan], 403);

        // Penjaga rute: wajib masuk, peran sesuai, dan token CSRF pada setiap POST.
        // (Closure biasa, bukan fn: $pemakai harus ditangkap sebagai referensi.)
        $jaga = function (?array $peran = null) use (&$pemakai, $sesi, $pengguna, $kini, $alihkan, $tolak, $app): \Closure {
            return function (Permintaan $req, Penangan $lanjut) use (&$pemakai, $peran, $sesi, $pengguna, $kini, $alihkan, $tolak, $app): Respons {
            $id = $sesi->idPengguna($kini());
            $u = $id !== null ? $pengguna->cari($id) : null;
            if ($u === null || !(int) $u['aktif']) {
                $sesi->keluar();
                $res = $app->getResponseFactory()->createResponse();
                if (str_starts_with($req->getUri()->getPath(), '/api/')) {
                    $res->getBody()->write('{"galat":"belum masuk"}');
                    return $res->withStatus(401)->withHeader('Content-Type', 'application/json');
                }
                return $alihkan($res, '/masuk');
            }
            $pemakai = $u;
            if ($peran !== null && !in_array($u['peran'], $peran, true)) {
                return $tolak('Tidak diizinkan', 'Peran Anda tidak punya akses ke halaman atau tindakan ini.');
            }
            if ($req->getMethod() === 'POST' && !$sesi->cocok(((array) $req->getParsedBody())['csrf'] ?? null)) {
                return $tolak('Form kedaluwarsa', 'Form ini sudah kedaluwarsa. Kembali ke halaman sebelumnya, muat ulang, lalu coba lagi.');
            }
            return $lanjut->handle($req);
            };
        };

        // ================================================================ masuk dan keluar
        $app->get('/masuk', function (Permintaan $req, Respons $res) use ($tampilan, $sesi, $pengguna, $kini, $alihkan) {
            $id = $sesi->idPengguna($kini());
            if ($id !== null && $pengguna->cari($id) !== null) {
                return $alihkan($res, '/');
            }
            return $tampilan->halaman($res, 'masuk', [
                'judul' => 'Masuk',
                'csrf' => $sesi->token(),
                'belumAdaPengguna' => $pengguna->jumlah() === 0,
            ]);
        });

        $app->post('/masuk', function (Permintaan $req, Respons $res) use ($sesi, $pengguna, $kini, $alihkan, $form, $tolak, $sso) {
            if ($sso !== null) {
                return $tolak('Masuk dengan Google', 'Login memakai akun Google. Buka halaman masuk lalu pilih "Masuk dengan Google".');
            }
            $f = $form($req);
            if (!$sesi->cocok($f['csrf'] ?? null)) {
                return $tolak('Form kedaluwarsa', 'Halaman masuk sudah kedaluwarsa. Buka ulang halaman masuk lalu coba lagi.');
            }
            $hasil = $pengguna->masuk((string) ($f['email'] ?? ''), (string) ($f['sandi'] ?? ''), $kini());
            if (is_string($hasil)) {
                $sesi->kilat($hasil, true);
                return $alihkan($res, '/masuk');
            }
            $sesi->masuk((int) $hasil['id'], $kini());
            return $alihkan($res, '/');
        });

        // --- Login Google: arahkan ke Google, lalu terima kodenya di /masuk/google/balik.
        $app->get('/masuk/google', function (Permintaan $req, Respons $res) use ($sesi, $sso, $alihkan) {
            if ($sso === null) {
                return $alihkan($res, '/masuk');
            }
            // Cookie sesi terikat pada nama host, jadi alur harus dimulai dari alamat resmi aplikasi.
            if ($req->getUri()->getHost() !== parse_url($sso->urlAplikasi(), PHP_URL_HOST)) {
                return $alihkan($res, $sso->urlAplikasi() . '/masuk');
            }
            $state = bin2hex(random_bytes(16));
            $nonce = bin2hex(random_bytes(16));
            $sesi->taruh('sso', ['state' => $state, 'nonce' => $nonce]);
            return $alihkan($res, $sso->urlOtorisasi($state, $nonce));
        });

        $app->get('/masuk/google/balik', function (Permintaan $req, Respons $res) use ($sesi, $sso, $pengguna, $kini, $alihkan) {
            if ($sso === null) {
                return $alihkan($res, '/masuk');
            }
            $p = $req->getQueryParams();
            $titip = $sesi->ambilSekali('sso');
            if (!is_array($titip) || !is_string($p['state'] ?? null) || !hash_equals($titip['state'], $p['state']) || !is_string($p['code'] ?? null)) {
                $sesi->kilat(isset($p['error']) ? 'Masuk dengan Google dibatalkan.' : 'Permintaan masuk sudah kedaluwarsa. Coba lagi.', true);
                return $alihkan($res, '/masuk');
            }
            try {
                $akun = $sso->tukar($p['code'], $titip['nonce'], $kini());
            } catch (\RuntimeException $e) {
                $sesi->kilat($e->getMessage(), true);
                return $alihkan($res, '/masuk');
            }
            $u = $pengguna->cariEmail($akun['email']);
            if ($u === null || !(int) $u['aktif']) {
                $sesi->kilat("Akun Google {$akun['email']} belum terdaftar di SILASE-EWS. Minta admin menambahkannya.", true);
                return $alihkan($res, '/masuk');
            }
            $sesi->masuk((int) $u['id'], $kini());
            return $alihkan($res, '/');
        });

        $app->post('/keluar', function (Permintaan $req, Respons $res) use ($sesi, $alihkan) {
            $sesi->keluar();
            return $alihkan($res, '/masuk');
        })->add($jaga());

        // ================================================================ akun sendiri
        $app->get('/akun', function (Permintaan $req, Respons $res) use ($tampilan) {
            return $tampilan->halaman($res, 'akun', ['judul' => 'Akun', 'menu' => 'akun']);
        })->add($jaga());

        $app->post('/akun/sandi', function (Permintaan $req, Respons $res) use (&$pemakai, $pengguna, $sesi, $kini, $alihkan, $form, $sso) {
            if ($sso !== null) {
                $sesi->kilat('Login memakai akun Google, jadi tidak ada kata sandi yang diatur di sini.', true);
                return $alihkan($res, '/akun');
            }
            $f = $form($req);
            $baru = (string) ($f['sandi_baru'] ?? '');
            if (!$pengguna->cocokSandi($pemakai, (string) ($f['sandi_lama'] ?? ''))) {
                $sesi->kilat('Kata sandi lama salah.', true);
            } elseif (($galat = PenggunaRepo::periksaSandi($baru)) !== null) {
                $sesi->kilat($galat, true);
            } elseif ($baru !== (string) ($f['sandi_ulang'] ?? '')) {
                $sesi->kilat('Kata sandi baru dan ulangannya tidak sama.', true);
            } else {
                $pengguna->setSandi((int) $pemakai['id'], $baru);
                $sesi->masuk((int) $pemakai['id'], $kini());       // ganti ID sesi setelah kata sandi berubah
                $sesi->kilat('Kata sandi diganti.');
            }
            return $alihkan($res, '/akun');
        })->add($jaga());

        // ================================================================ ringkasan dan detail drum
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
        })->add($jaga());

        $app->get('/drum/{id}', function (Permintaan $req, Respons $res, array $arg) use (&$pemakai, $repo, $siklus, $tampilan, $kini) {
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
                'siklusBerjalan' => $siklus->berjalan($device['device_id']),
                'bolehKelola' => in_array($pemakai['peran'], self::PENGELOLA, true),
                'skrip' => ['/assets/chart.umd.js', '/assets/drum.js'],
            ]);
        })->add($jaga());

        $app->post('/drum/{id}/ubah', function (Permintaan $req, Respons $res, array $arg) use ($pdo, $repo, $sesi, $alihkan, $form, $teks) {
            $device = $repo->satu($arg['id']);
            if ($device === null) {
                return $res->withStatus(404);
            }
            $f = $form($req);
            $nama = $teks($f, 'nama', 100);
            if ($nama === null) {
                $sesi->kilat('Nama drum wajib diisi.', true);
            } else {
                $pdo->prepare('UPDATE devices SET nama = ?, lokasi = ?, catatan = ? WHERE device_id = ?')
                    ->execute([$nama, $teks($f, 'lokasi', 100), $teks($f, 'catatan', 2000), $device['device_id']]);
                $sesi->kilat('Data drum disimpan.');
            }
            return $alihkan($res, '/drum/' . rawurlencode($device['device_id']));
        })->add($jaga(self::PENGELOLA));

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
        })->add($jaga());

        // ================================================================ peringatan
        $app->get('/peringatan', function (Permintaan $req, Respons $res) use (&$pemakai, $alert, $tampilan) {
            return $tampilan->halaman($res, 'peringatan', [
                'judul' => 'Peringatan',
                'menu' => 'peringatan',
                'aktif' => $alert->aktif(),
                'riwayat' => $alert->riwayat(),
                'bolehAkui' => in_array($pemakai['peran'], self::PENGELOLA, true),
                'segarkan' => 60,
            ]);
        })->add($jaga());

        $app->post('/peringatan/{id:[0-9]+}/akui', function (Permintaan $req, Respons $res, array $arg) use (&$pemakai, $alert, $sesi, $kini, $alihkan) {
            $alert->akui((int) $arg['id'], $kini(), (int) $pemakai['id'])
                ? $sesi->kilat('Peringatan diakui. Pengingat WhatsApp untuk peringatan ini dihentikan.')
                : $sesi->kilat('Peringatan itu sudah diakui atau tidak ditemukan.', true);
            return $alihkan($res, '/peringatan');
        })->add($jaga(self::PENGELOLA));

        // ================================================================ siklus fermentasi
        $app->get('/siklus', function (Permintaan $req, Respons $res) use (&$pemakai, $repo, $siklus, $tampilan) {
            return $tampilan->halaman($res, 'siklus', [
                'judul' => 'Siklus',
                'menu' => 'siklus',
                'drum' => $repo->semua(),
                'daftar' => $siklus->semua(),
                'bolehKelola' => in_array($pemakai['peran'], self::PENGELOLA, true),
            ]);
        })->add($jaga());

        // Mulai siklus = perintah start_cycle ke node + baris cycles. Baris hanya dibuat bila perintah sampai ke broker.
        $app->post('/siklus/mulai', function (Permintaan $req, Respons $res) use (&$pemakai, $repo, $siklus, $log, $perintah, $sesi, $kini, $alihkan, $form, $teks) {
            $f = $form($req);
            $device = $repo->satu((string) ($f['device_id'] ?? ''));
            if ($device === null) {
                $sesi->kilat('Pilih drum yang terdaftar.', true);
            } elseif (($f['yakin'] ?? '') !== '1') {
                $sesi->kilat('Centang konfirmasi sebelum memulai siklus baru.', true);
            } elseif ($siklus->berjalan($device['device_id']) !== null) {
                $sesi->kilat("{$device['nama']} masih punya siklus yang berjalan. Tutup dulu siklus itu.", true);
            } elseif ($perintah === null) {
                $sesi->kilat('Broker MQTT belum dikonfigurasi, perintah tidak bisa dikirim.', true);
            } else {
                $saat = $kini();
                try {
                    $perintah->kirim($device['device_id'], 'start_cycle');
                    $log->catat((int) $pemakai['id'], $device['device_id'], 'start_cycle', true, $saat);
                    $siklus->mulai($device['device_id'], $teks($f, 'bahan_pakan', 150), $teks($f, 'catatan', 2000), (int) $pemakai['id'], $saat);
                    $sesi->kilat("Siklus baru {$device['nama']} dimulai. Umur siklus di node kembali ke nol pada pembacaan berikutnya, bila node sedang tersambung.");
                } catch (\RuntimeException $e) {
                    $log->catat((int) $pemakai['id'], $device['device_id'], 'start_cycle', false, $saat);
                    $sesi->kilat('Siklus tidak dimulai: ' . $e->getMessage(), true);
                }
            }
            return $alihkan($res, '/siklus');
        })->add($jaga(self::PENGELOLA));

        $app->post('/siklus/{id:[0-9]+}/tutup', function (Permintaan $req, Respons $res, array $arg) use ($siklus, $sesi, $kini, $alihkan, $form, $teks) {
            $f = $form($req);
            $hasil = (string) ($f['hasil'] ?? '');
            $ph = str_replace(',', '.', trim((string) ($f['ph_akhir'] ?? '')));
            if (!isset(SiklusRepo::HASIL[$hasil])) {
                $sesi->kilat('Pilih hasil akhir: baik atau rusak.', true);
            } elseif ($ph !== '' && (!is_numeric($ph) || (float) $ph < 0 || (float) $ph > 14)) {
                $sesi->kilat('pH akhir harus angka antara 0 dan 14.', true);
            } elseif (!$siklus->tutup((int) $arg['id'], $hasil, $ph === '' ? null : number_format((float) $ph, 2, '.', ''), $teks($f, 'catatan_organoleptik', 2000), $kini())) {
                $sesi->kilat('Siklus itu sudah ditutup atau tidak ditemukan.', true);
            } else {
                $sesi->kilat('Siklus ditutup dan hasil akhirnya disimpan.');
            }
            return $alihkan($res, '/siklus');
        })->add($jaga(self::PENGELOLA));

        // ================================================================ perintah ke node
        $app->get('/perintah', function (Permintaan $req, Respons $res) use ($repo, $log, $tampilan, $perintah) {
            return $tampilan->halaman($res, 'perintah', [
                'judul' => 'Perintah',
                'menu' => 'perintah',
                'drum' => $repo->semua(),
                'riwayat' => $log->terakhir(),
                'siap' => $perintah !== null,
            ]);
        })->add($jaga(self::PENGELOLA));

        $app->post('/perintah', function (Permintaan $req, Respons $res) use (&$pemakai, $repo, $log, $perintah, $sesi, $kini, $alihkan, $form) {
            $f = $form($req);
            $device = $repo->satu((string) ($f['device_id'] ?? ''));
            $apa = (string) ($f['perintah'] ?? '');
            if ($device === null) {
                $sesi->kilat('Pilih drum yang terdaftar.', true);
            } elseif (!in_array($apa, ['sample_now', 'mute'], true)) {
                $sesi->kilat('Perintah tidak dikenal.', true);       // start_cycle hanya lewat halaman Siklus
            } elseif (($f['yakin'] ?? '') !== '1') {
                $sesi->kilat('Centang konfirmasi sebelum mengirim perintah.', true);
            } elseif ($perintah === null) {
                $sesi->kilat('Broker MQTT belum dikonfigurasi, perintah tidak bisa dikirim.', true);
            } else {
                $label = PengirimPerintah::PERINTAH[$apa];
                try {
                    $perintah->kirim($device['device_id'], $apa);
                    $log->catat((int) $pemakai['id'], $device['device_id'], $apa, true, $kini());
                    $sesi->kilat("Perintah \"$label\" dikirim ke {$device['nama']}. Node menjalankannya bila sedang tersambung.");
                } catch (\RuntimeException $e) {
                    $log->catat((int) $pemakai['id'], $device['device_id'], $apa, false, $kini());
                    $sesi->kilat("Perintah \"$label\" gagal dikirim: " . $e->getMessage(), true);
                }
            }
            return $alihkan($res, '/perintah');
        })->add($jaga(self::PENGELOLA));

        // ================================================================ ekspor CSV
        $app->get('/ekspor', function (Permintaan $req, Respons $res) use ($repo, $siklus, $tampilan, $kini) {
            return $tampilan->halaman($res, 'ekspor', [
                'judul' => 'Ekspor',
                'menu' => 'ekspor',
                'drum' => $repo->semua(),
                'siklus' => $siklus->semua(),
                'hariIni' => gmdate('Y-m-d', $kini() + 7 * 3600),
                'seminggu' => gmdate('Y-m-d', $kini() + 7 * 3600 - 6 * 86400),
            ]);
        })->add($jaga());

        $app->get('/ekspor/unduh', function (Permintaan $req, Respons $res) use ($pdo, $repo, $siklus, $sesi, $kini, $alihkan) {
            $p = $req->getQueryParams();
            $tanggal = '/^\d{4}-\d{2}-\d{2}$/';
            if (isset($p['siklus']) && $p['siklus'] !== '') {
                $s = $siklus->satu((int) $p['siklus']);
                if ($s === null) {
                    $sesi->kilat('Siklus tidak ditemukan.', true);
                    return $alihkan($res, '/ekspor');
                }
                $deviceId = $s['device_id'];
                $dari = Format::epoch($s['mulai']);
                $sampai = $s['selesai'] !== null ? Format::epoch($s['selesai']) : $kini();
                $nama = "silase_{$deviceId}_siklus-{$s['id']}.csv";
            } else {
                $device = $repo->satu((string) ($p['device_id'] ?? ''));
                $a = (string) ($p['dari'] ?? '');
                $b = (string) ($p['sampai'] ?? '');
                if ($device === null || !preg_match($tanggal, $a) || !preg_match($tanggal, $b) || $a > $b) {
                    $sesi->kilat('Pilih drum dan rentang tanggal yang benar (tanggal awal tidak boleh setelah tanggal akhir).', true);
                    return $alihkan($res, '/ekspor');
                }
                // Tanggal dimaknai sebagai hari WIB penuh.
                $deviceId = $device['device_id'];
                $dari = (int) strtotime("$a 00:00:00 +07:00");
                $sampai = (int) strtotime("$b 23:59:59 +07:00");
                $nama = "silase_{$deviceId}_" . str_replace('-', '', $a) . '-' . str_replace('-', '', $b) . '.csv';
            }

            $berkas = fopen('php://temp', 'w+b');
            Csv::tulis($berkas, $pdo, $deviceId, $dari, $sampai);
            rewind($berkas);
            return $res->withBody(new Stream($berkas))
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nama) . '"')
                ->withHeader('Cache-Control', 'no-store');
        })->add($jaga());

        // ================================================================ pengaturan WhatsApp (admin)
        $app->get('/whatsapp', function (Permintaan $req, Respons $res) use ($repo, $penerima, $pengaturan, $tampilan, $wa, $kini) {
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
                'waTerputus' => $status !== null && !$status['ok'],
                'segarkan' => ($status !== null && !$status['ok']) ? 30 : null,
            ]);
        })->add($jaga(self::ADMIN));

        $app->get('/whatsapp/qr.png', function (Permintaan $req, Respons $res) use ($wa) {
            $png = $wa instanceof PunyaQr ? $wa->qr() : null;
            if ($png === null) {
                return $res->withStatus(404);
            }
            $res->getBody()->write($png);
            return $res->withHeader('Content-Type', 'image/png')->withHeader('Cache-Control', 'no-store');
        })->add($jaga(self::ADMIN));

        $app->post('/whatsapp/penerima', function (Permintaan $req, Respons $res) use ($repo, $penerima, $sesi, $alihkan, $form) {
            $f = $form($req);
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
        })->add($jaga(self::ADMIN));

        $app->post('/whatsapp/penerima/{id:[0-9]+}/aktif', function (Permintaan $req, Respons $res, array $arg) use ($penerima, $alihkan) {
            $penerima->balikAktif((int) $arg['id']);
            return $alihkan($res, '/whatsapp');
        })->add($jaga(self::ADMIN));

        $app->post('/whatsapp/penerima/{id:[0-9]+}/hapus', function (Permintaan $req, Respons $res, array $arg) use ($penerima, $sesi, $alihkan) {
            $penerima->hapus((int) $arg['id']);
            $sesi->kilat('Penerima dihapus.');
            return $alihkan($res, '/whatsapp');
        })->add($jaga(self::ADMIN));

        // Pesan uji hanya ke nomor yang terdaftar sebagai penerima.
        $app->post('/whatsapp/uji', function (Permintaan $req, Respons $res) use ($pdo, $penerima, $sesi, $kini, $alihkan, $form) {
            $p = $penerima->satu((int) ($form($req)['penerima_id'] ?? 0));
            if ($p === null) {
                $sesi->kilat('Pilih penerima untuk pesan uji.', true);
            } else {
                (new Antrean($pdo, PabrikNotifier::namaProvider()))->uji($p['nomor'], $kini());
                $sesi->kilat("Pesan uji untuk {$p['nama']} masuk antrean. Lihat statusnya di tabel antrean di bawah.");
            }
            return $alihkan($res, '/whatsapp');
        })->add($jaga(self::ADMIN));

        // ================================================================ kelola pengguna (admin)
        $app->get('/pengguna', function (Permintaan $req, Respons $res) use ($pengguna, $tampilan) {
            return $tampilan->halaman($res, 'pengguna', [
                'judul' => 'Pengguna',
                'menu' => 'pengguna',
                'daftar' => $pengguna->semua(),
            ]);
        })->add($jaga(self::ADMIN));

        $app->post('/pengguna', function (Permintaan $req, Respons $res) use ($pengguna, $sesi, $kini, $alihkan, $form, $sso) {
            $f = $form($req);
            [$nama, $email, $peran] = [(string) ($f['nama'] ?? ''), (string) ($f['email'] ?? ''), (string) ($f['peran'] ?? '')];
            // Dengan login Google tidak ada kata sandi: cukup daftarkan alamat emailnya.
            $sandi = $sso !== null ? null : (string) ($f['sandi'] ?? '');
            if (($galat = PenggunaRepo::periksa($nama, $email, $peran, $sandi)) !== null) {
                $sesi->kilat($galat, true);
            } elseif ($pengguna->cariEmail($email) !== null) {
                $sesi->kilat('Email itu sudah dipakai pengguna lain.', true);
            } else {
                $pengguna->buat($nama, $email, $peran, $sandi, $kini());
                $sesi->kilat(trim($nama) . ' ditambahkan sebagai ' . strtolower(PenggunaRepo::PERAN[$peran]) . '. '
                    . ($sso !== null ? 'Ia bisa langsung masuk dengan akun Google itu.' : 'Sampaikan kata sandinya secara langsung.'));
            }
            return $alihkan($res, '/pengguna');
        })->add($jaga(self::ADMIN));

        $app->post('/pengguna/{id:[0-9]+}/ubah', function (Permintaan $req, Respons $res, array $arg) use ($pengguna, $sesi, $alihkan, $form) {
            $f = $form($req);
            $u = $pengguna->cari((int) $arg['id']);
            $peran = (string) ($f['peran'] ?? '');
            $aktif = ($f['aktif'] ?? '') === '1';
            if ($u === null || !isset(PenggunaRepo::PERAN[$peran])) {
                $sesi->kilat('Pengguna atau peran tidak dikenal.', true);
            } elseif ($u['peran'] === 'admin' && (int) $u['aktif'] && ($peran !== 'admin' || !$aktif) && $pengguna->jumlahAdminAktif() <= 1) {
                // Tanpa admin aktif, tidak ada lagi yang bisa mengelola pengguna.
                $sesi->kilat('Harus ada paling sedikit satu admin aktif.', true);
            } else {
                $pengguna->ubah((int) $u['id'], $peran, $aktif);
                $sesi->kilat("Data {$u['nama']} disimpan.");
            }
            return $alihkan($res, '/pengguna');
        })->add($jaga(self::ADMIN));

        $app->post('/pengguna/{id:[0-9]+}/sandi', function (Permintaan $req, Respons $res, array $arg) use ($pengguna, $sesi, $alihkan, $form, $sso) {
            if ($sso !== null) {
                $sesi->kilat('Login memakai akun Google, jadi tidak ada kata sandi yang bisa diganti.', true);
                return $alihkan($res, '/pengguna');
            }
            $u = $pengguna->cari((int) $arg['id']);
            $sandi = (string) ($form($req)['sandi'] ?? '');
            if ($u === null) {
                $sesi->kilat('Pengguna tidak ditemukan.', true);
            } elseif (($galat = PenggunaRepo::periksaSandi($sandi)) !== null) {
                $sesi->kilat($galat, true);
            } else {
                $pengguna->setSandi((int) $u['id'], $sandi);
                $sesi->kilat("Kata sandi {$u['nama']} diganti. Sampaikan kata sandi barunya secara langsung.");
            }
            return $alihkan($res, '/pengguna');
        })->add($jaga(self::ADMIN));

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
