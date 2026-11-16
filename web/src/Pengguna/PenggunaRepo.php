<?php

declare(strict_types=1);

namespace Silase\Pengguna;

use PDO;
use Silase\Pengaturan;

final class PenggunaRepo
{
    public const PERAN = ['admin' => 'Admin', 'operator' => 'Operator', 'peneliti' => 'Peneliti'];
    public const SANDI_MIN = 10;
    private const GAGAL_MAKS = 5;
    private const KUNCI_S = 900;        // 15 menit terkunci setelah 5 kali gagal
    /** Hash tiruan agar waktu jawab sama untuk email yang tidak terdaftar. */
    private const HASH_TIRUAN = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(private PDO $pdo, private Pengaturan $pengaturan)
    {
    }

    /** @return array<string, mixed>|null */
    public function cari(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function cariEmail(string $email): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $q->execute([self::rapikanEmail($email)]);
        return $q->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function semua(): array
    {
        return $this->pdo->query('SELECT * FROM users ORDER BY aktif DESC, nama, id')->fetchAll();
    }

    public function jumlah(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function jumlahAdminAktif(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE peran = 'admin' AND aktif = 1")->fetchColumn();
    }

    /** @return string|null pesan galat, atau null bila masukan sah */
    public static function periksa(string $nama, string $email, string $peran, ?string $sandi): ?string
    {
        if (trim($nama) === '' || mb_strlen($nama) > 100) {
            return 'Nama wajib diisi (paling banyak 100 huruf).';
        }
        if (!filter_var(self::rapikanEmail($email), FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return 'Alamat email tidak valid.';
        }
        if (!isset(self::PERAN[$peran])) {
            return 'Peran tidak dikenal.';
        }
        return $sandi === null ? null : self::periksaSandi($sandi);
    }

    public static function periksaSandi(string $sandi): ?string
    {
        return strlen($sandi) < self::SANDI_MIN
            ? 'Kata sandi paling sedikit ' . self::SANDI_MIN . ' karakter.'
            : null;
    }

    /** @param string|null $sandi null untuk pengguna yang hanya masuk lewat Google */
    public function buat(string $nama, string $email, string $peran, ?string $sandi, int $kini): int
    {
        // Tanpa kata sandi: isi kolom dengan nilai yang tidak pernah cocok dengan kata sandi apa pun.
        $hash = $sandi !== null ? password_hash($sandi, PASSWORD_DEFAULT) : '!' . bin2hex(random_bytes(16));
        $this->pdo->prepare(
            'INSERT INTO users (nama, email, password_hash, peran, aktif, dibuat_pada) VALUES (?, ?, ?, ?, 1, ?)'
        )->execute([trim($nama), self::rapikanEmail($email), $hash, $peran, gmdate('Y-m-d H:i:s', $kini)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function ubah(int $id, string $peran, bool $aktif): void
    {
        $this->pdo->prepare('UPDATE users SET peran = ?, aktif = ? WHERE id = ?')->execute([$peran, $aktif ? 1 : 0, $id]);
    }

    public function setSandi(int $id, string $sandi): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($sandi, PASSWORD_DEFAULT), $id]);
    }

    public function cocokSandi(array $pengguna, string $sandi): bool
    {
        return password_verify($sandi, $pengguna['password_hash']);
    }

    /**
     * Memeriksa email dan kata sandi. Lima kali gagal berturut-turut mengunci
     * email itu selama 15 menit, apa pun kata sandi berikutnya.
     *
     * @return array<string, mixed>|string baris pengguna bila berhasil, atau pesan galat
     */
    public function masuk(string $email, string $sandi, int $kini): array|string
    {
        $kunci = 'masuk_gagal:' . sha1(self::rapikanEmail($email));
        $catatan = $this->pengaturan->ambilJson($kunci) ?? ['n' => 0, 'sampai' => 0];
        if ($catatan['sampai'] > $kini) {
            return 'Terlalu banyak percobaan. Coba lagi dalam ' . (int) ceil(($catatan['sampai'] - $kini) / 60) . ' menit.';
        }

        $u = $this->cariEmail($email);
        $cocok = password_verify($sandi, $u['password_hash'] ?? self::HASH_TIRUAN);
        if ($u === null || !$cocok || !(int) $u['aktif']) {
            $n = ($catatan['sampai'] > 0 && $catatan['sampai'] <= $kini ? 0 : (int) $catatan['n']) + 1;
            $this->pengaturan->simpanJson($kunci, ['n' => $n, 'sampai' => $n >= self::GAGAL_MAKS ? $kini + self::KUNCI_S : 0], $kini);
            return 'Email atau kata sandi salah.';
        }

        if ((int) $catatan['n'] > 0) {
            $this->pengaturan->simpanJson($kunci, ['n' => 0, 'sampai' => 0], $kini);
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $this->setSandi((int) $u['id'], $sandi);
        }
        return $u;
    }

    private static function rapikanEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
