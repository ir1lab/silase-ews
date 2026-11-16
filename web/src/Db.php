<?php

declare(strict_types=1);

namespace Silase;

use PDO;

final class Db
{
    /**
     * Sambungan ke MariaDB/MySQL dari .env. Sesi dipaksa UTC.
     * DB_SQLITE=<berkas> memakai SQLite sebagai gantinya; hanya untuk mencoba
     * ingest secara lokal tanpa server basis data, bukan untuk produksi.
     */
    public static function sambung(): PDO
    {
        $sqlite = Config::get('DB_SQLITE');
        if ($sqlite !== null) {
            return self::sqlite($sqlite, dirname(__DIR__) . '/sql/schema.sql');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::wajib('DB_HOST'),
            Config::get('DB_PORT', '3306'),
            Config::wajib('DB_NAME'),
        );
        $pdo = new PDO($dsn, Config::wajib('DB_USER'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    /** SQLite dengan skema yang sama (':memory:' pada uji otomatis). */
    public static function sqlite(string $berkas, string $berkasSkema): PDO
    {
        $pdo = new PDO('sqlite:' . $berkas, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::pasangSkema($pdo, $berkasSkema);
        return $pdo;
    }

    /** Menjalankan schema.sql; aman diulang (CREATE TABLE IF NOT EXISTS). */
    public static function pasangSkema(PDO $pdo, string $berkasSkema): void
    {
        $sql = file_get_contents($berkasSkema);
        if ($sql === false) {
            throw new \RuntimeException("Skema tidak terbaca: $berkasSkema");
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = self::sqlUntukSqlite($sql);
        }
        foreach (explode(';', $sql) as $perintah) {
            if (trim($perintah) !== '') {
                $pdo->exec($perintah);
            }
        }
    }

    public static function sqlUntukSqlite(string $sql): string
    {
        $ganti = [
            '/\b(BIG)?INT UNSIGNED AUTO_INCREMENT PRIMARY KEY/' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '/ENUM\([^)]*\)/' => 'TEXT',
            '/ UNSIGNED\b/' => '',
            '/^\s*UNIQUE KEY \w+ /m' => '  UNIQUE ',
            '/^\s*KEY \w+ \([^)]*\),?\s*$/m' => '',
            '/\) ENGINE=[^;]*/' => ')',
        ];
        return preg_replace(array_keys($ganti), array_values($ganti), $sql);
    }
}
