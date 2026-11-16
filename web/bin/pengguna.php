#!/usr/bin/env php
<?php

// Pengelolaan pengguna dari baris perintah (untuk pemasangan dan pemulihan).
//
//   php bin/pengguna.php admin-awal [email]   membuat admin pertama bila belum ada pengguna
//   php bin/pengguna.php sandi <email>        memberi kata sandi acak baru (hanya login kata sandi)
//
// Dengan login Google (GOOGLE_CLIENT_ID diisi), admin pertama cukup didaftarkan
// dengan alamat email akun Google-nya; tidak ada kata sandi.
// Dengan login kata sandi, kata sandi acak ditampilkan satu kali.

declare(strict_types=1);

use Silase\Config;
use Silase\Db;
use Silase\Pengaturan;
use Silase\Pengguna\PenggunaRepo;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::muat(dirname(__DIR__));
$pdo = Db::sambung();
$repo = new PenggunaRepo($pdo, new Pengaturan($pdo));
$acak = fn (): string => rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'xy'), '=');

switch ($argv[1] ?? '') {
    case 'admin-awal':
        if ($repo->jumlah() > 0) {
            echo "Sudah ada pengguna; admin awal tidak dibuat.\n";
            exit(0);
        }
        $sso = Config::get('GOOGLE_CLIENT_ID') !== null;
        $email = trim((string) ($argv[2] ?? '')) ?: trim(explode(',', (string) Config::get('ADMIN_EMAIL', ''))[0]);
        if ($sso) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fwrite(STDERR, "Login memakai Google: sebutkan email akun Google admin, mis.\n  php bin/pengguna.php admin-awal nama@gmail.com\n");
                exit(1);
            }
            $repo->buat('Admin', $email, 'admin', null, time());
            echo "ADMIN PERTAMA DIDAFTARKAN: $email\nMasuk lewat tombol \"Masuk dengan Google\" memakai akun itu.\n";
            break;
        }
        $email = $email ?: 'admin@silase.local';
        $sandi = $acak();
        $repo->buat('Admin', $email, 'admin', $sandi, time());
        echo "ADMIN PERTAMA DIBUAT (catat sekarang, tidak ditampilkan lagi):\n";
        echo "  email      : $email\n  kata sandi : $sandi\n";
        echo "Ganti kata sandi lewat halaman Akun setelah masuk.\n";
        break;

    case 'sandi':
        $u = $repo->cariEmail((string) ($argv[2] ?? ''));
        if ($u === null) {
            fwrite(STDERR, "Pengguna dengan email itu tidak ditemukan.\n");
            exit(1);
        }
        $sandi = $acak();
        $repo->setSandi((int) $u['id'], $sandi);
        $repo->ubah((int) $u['id'], $u['peran'], true);
        echo "Kata sandi baru untuk {$u['email']} (catat sekarang): $sandi\n";
        break;

    default:
        fwrite(STDERR, "Pakai: php bin/pengguna.php admin-awal | sandi <email>\n");
        exit(2);
}
