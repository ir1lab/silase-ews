<?php

declare(strict_types=1);

namespace Silase\Web;

/**
 * Sesi untuk token CSRF dan pesan sekali tampil. Sesi PHP baru dimulai saat
 * benar-benar dipakai, jadi halaman tanpa form tidak membuat cookie.
 */
final class Sesi
{
    private const NAMA_COOKIE = 'silase_sesi';

    /** @var array<string, mixed> */
    private array $memori = [];

    private function __construct(private bool $php)
    {
    }

    public static function php(): self
    {
        return new self(true);
    }

    /** Sesi di memori, untuk uji otomatis. */
    public static function memori(): self
    {
        return new self(false);
    }

    public function token(): string
    {
        $token = $this->ambil('csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->simpan('csrf', $token);
        }
        return $token;
    }

    public function cocok(mixed $kiriman): bool
    {
        $token = $this->ada() ? $this->ambil('csrf') : null;
        return is_string($token) && is_string($kiriman) && hash_equals($token, $kiriman);
    }

    public function kilat(string $pesan, bool $galat = false): void
    {
        $this->simpan('kilat', ['pesan' => $pesan, 'galat' => $galat]);
    }

    /** @return array{pesan: string, galat: bool}|null pesan sekali tampil, lalu dihapus */
    public function ambilKilat(): ?array
    {
        if (!$this->ada()) {
            return null;
        }
        $kilat = $this->ambil('kilat');
        if ($kilat !== null) {
            $this->simpan('kilat', null);
        }
        return is_array($kilat) ? $kilat : null;
    }

    /** Menyimpan nilai sementara, mis. state login Google. */
    public function taruh(string $kunci, mixed $nilai): void
    {
        $this->simpan('x_' . $kunci, $nilai);
    }

    /** Mengambil nilai sementara lalu menghapusnya (sekali pakai). */
    public function ambilSekali(string $kunci): mixed
    {
        if (!$this->ada()) {
            return null;
        }
        $nilai = $this->ambil('x_' . $kunci);
        if ($nilai !== null) {
            $this->simpan('x_' . $kunci, null);
        }
        return $nilai;
    }

    /** Mencatat pengguna yang masuk. ID sesi diganti untuk mencegah session fixation. */
    public function masuk(int $idPengguna, int $kini): void
    {
        $this->mulai();
        if ($this->php) {
            session_regenerate_id(true);
        }
        $this->simpan('pengguna', $idPengguna);
        $this->simpan('masuk_pada', $kini);
        $this->simpan('csrf', bin2hex(random_bytes(32)));
    }

    public function keluar(): void
    {
        if (!$this->ada()) {
            return;
        }
        $this->mulai();
        if ($this->php) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $this->memori = [];
        }
    }

    /** @return int|null ID pengguna yang sedang masuk; null bila belum masuk atau sesi sudah lewat batas */
    public function idPengguna(int $kini, int $umurMaks = 43200): ?int
    {
        if (!$this->ada()) {
            return null;
        }
        $id = $this->ambil('pengguna');
        $sejak = $this->ambil('masuk_pada');
        if (!is_int($id) || !is_int($sejak) || $kini - $sejak > $umurMaks) {
            return null;
        }
        return $id;
    }

    /** Apakah pengunjung sudah punya sesi (tanpa membuat yang baru). */
    private function ada(): bool
    {
        return !$this->php || session_status() === PHP_SESSION_ACTIVE || isset($_COOKIE[self::NAMA_COOKIE]);
    }

    private function mulai(): void
    {
        if (!$this->php || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::NAMA_COOKIE);
        session_set_cookie_params([
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ]);
        session_start();
    }

    private function ambil(string $kunci): mixed
    {
        $this->mulai();
        return $this->php ? ($_SESSION[$kunci] ?? null) : ($this->memori[$kunci] ?? null);
    }

    private function simpan(string $kunci, mixed $nilai): void
    {
        $this->mulai();
        if ($this->php) {
            $_SESSION[$kunci] = $nilai;
        } else {
            $this->memori[$kunci] = $nilai;
        }
    }
}
