<?php

declare(strict_types=1);

namespace Silase\Tests;

use Silase\Notifikasi\HttpKlien;

/** Klien HTTP tiruan: menjawab berurutan dari daftar, mencatat permintaan. */
final class HttpTiruan implements HttpKlien
{
    /** @var list<array{string, string, array<string, string>, ?string}> */
    public array $permintaan = [];

    /** @param list<array{int, string}|\Throwable> $jawaban */
    public function __construct(private array $jawaban)
    {
    }

    public function kirim(string $metode, string $url, array $kepala = [], ?string $badan = null): array
    {
        $this->permintaan[] = [$metode, $url, $kepala, $badan];
        $j = array_shift($this->jawaban) ?? [200, '{}'];
        if ($j instanceof \Throwable) {
            throw $j;
        }
        return $j;
    }
}
