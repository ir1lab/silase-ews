<?php

declare(strict_types=1);

namespace Silase\Tests;

use Silase\Perintah\PengirimPerintah;

/** Pengirim perintah tiruan: mencatat kiriman, bisa disetel gagal. */
final class PerintahTiruan implements PengirimPerintah
{
    /** @var list<array{string, string}> */
    public array $terkirim = [];
    public bool $gagal = false;

    public function kirim(string $deviceId, string $perintah): void
    {
        if ($this->gagal) {
            throw new \RuntimeException('broker tidak menerima perintah: sambungan ditolak');
        }
        $this->terkirim[] = [$deviceId, $perintah];
    }
}
