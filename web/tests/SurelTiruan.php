<?php

declare(strict_types=1);

namespace Silase\Tests;

use Silase\Notifikasi\PengirimSurel;

final class SurelTiruan implements PengirimSurel
{
    /** @var list<array{string, string}> */
    public array $terkirim = [];

    public function kirim(string $subjek, string $isi): void
    {
        $this->terkirim[] = [$subjek, $isi];
    }
}
