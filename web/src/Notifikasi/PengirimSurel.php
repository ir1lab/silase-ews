<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

interface PengirimSurel
{
    public function kirim(string $subjek, string $isi): void;
}
