<?php

declare(strict_types=1);

namespace Silase\Masuk;

use Silase\Config;
use Silase\Notifikasi\HttpGagal;
use Silase\Notifikasi\HttpKlien;

/**
 * Login dengan akun Google (OpenID Connect, alur authorization code).
 *
 * Google hanya membuktikan siapa orangnya. Yang boleh masuk tetap ditentukan
 * tabel users: alamat email harus sudah didaftarkan admin dan masih aktif.
 */
final class GoogleSso
{
    private const URL_OTORISASI = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const URL_TOKEN = 'https://oauth2.googleapis.com/token';
    private const PENERBIT = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        private HttpKlien $http,
        private string $idKlien,
        private string $rahasia,
        private string $urlAplikasi,
    ) {
        $this->urlAplikasi = rtrim($urlAplikasi, '/');
    }

    /** @return self|null null bila login Google belum dikonfigurasi (dipakai login kata sandi) */
    public static function dariEnv(HttpKlien $http): ?self
    {
        $id = Config::get('GOOGLE_CLIENT_ID');
        $rahasia = Config::get('GOOGLE_CLIENT_SECRET');
        $url = Config::get('APP_URL');
        return ($id === null || $rahasia === null || $url === null) ? null : new self($http, $id, $rahasia, $url);
    }

    public function urlAplikasi(): string
    {
        return $this->urlAplikasi;
    }

    public function urlBalik(): string
    {
        return $this->urlAplikasi . '/masuk/google/balik';
    }

    /** Alamat halaman persetujuan Google. $state dan $nonce disimpan di sesi pengunjung. */
    public function urlOtorisasi(string $state, string $nonce): string
    {
        return self::URL_OTORISASI . '?' . http_build_query([
            'client_id' => $this->idKlien,
            'redirect_uri' => $this->urlBalik(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Menukar kode dari Google dengan identitas pengguna.
     *
     * Tanda tangan id_token tidak diperiksa ulang karena token diterima langsung
     * dari Google lewat TLS (diizinkan OpenID Connect Core 3.1.3.7); yang
     * diperiksa adalah penerbit, tujuan, masa berlaku, nonce, dan email_verified.
     *
     * @return array{email: string, nama: string}
     * @throws \RuntimeException dengan pesan yang aman ditampilkan ke pengguna
     */
    public function tukar(string $kode, string $nonce, int $kini): array
    {
        try {
            [$status, $badan] = $this->http->kirim('POST', self::URL_TOKEN, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
                'code' => $kode,
                'client_id' => $this->idKlien,
                'client_secret' => $this->rahasia,
                'redirect_uri' => $this->urlBalik(),
                'grant_type' => 'authorization_code',
            ]));
        } catch (HttpGagal) {
            throw new \RuntimeException('Google tidak bisa dihubungi. Coba lagi sebentar lagi.');
        }
        $token = json_decode($badan, true)['id_token'] ?? null;
        if ($status !== 200 || !is_string($token)) {
            throw new \RuntimeException('Google menolak permintaan masuk. Coba lagi dari halaman masuk.');
        }

        $bagian = explode('.', $token);
        $klaim = count($bagian) === 3
            ? json_decode((string) base64_decode(strtr($bagian[1], '-_', '+/'), true), true)
            : null;
        if (
            !is_array($klaim)
            || !in_array($klaim['iss'] ?? null, self::PENERBIT, true)
            || ($klaim['aud'] ?? null) !== $this->idKlien
            || (int) ($klaim['exp'] ?? 0) < $kini
            || !is_string($klaim['nonce'] ?? null) || !hash_equals($nonce, $klaim['nonce'])
        ) {
            throw new \RuntimeException('Jawaban dari Google tidak valid. Coba lagi dari halaman masuk.');
        }
        if (($klaim['email_verified'] ?? false) !== true || !is_string($klaim['email'] ?? null)) {
            throw new \RuntimeException('Alamat email akun Google itu belum terverifikasi.');
        }
        return ['email' => strtolower($klaim['email']), 'nama' => (string) ($klaim['name'] ?? $klaim['email'])];
    }
}
