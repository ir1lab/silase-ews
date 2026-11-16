<?php

declare(strict_types=1);

namespace Silase;

use Dotenv\Dotenv;

/** Konfigurasi dari .env (atau variabel lingkungan proses). */
final class Config
{
    public static function muat(string $dirWeb): void
    {
        Dotenv::createImmutable($dirWeb)->safeLoad();
    }

    public static function get(string $kunci, ?string $bawaan = null): ?string
    {
        $nilai = $_ENV[$kunci] ?? getenv($kunci);
        return ($nilai === false || $nilai === null || $nilai === '') ? $bawaan : (string) $nilai;
    }

    public static function wajib(string $kunci): string
    {
        $nilai = self::get($kunci);
        if ($nilai === null) {
            throw new \RuntimeException("Konfigurasi $kunci belum diisi di .env");
        }
        return $nilai;
    }

    public static function benar(string $kunci): bool
    {
        return in_array(strtolower((string) self::get($kunci, '0')), ['1', 'true', 'ya', 'yes'], true);
    }
}
