<?php

declare(strict_types=1);

namespace Silase\Ingest;

final class HasilIngest
{
    /**
     * @param bool $disimpan false bila baris ditolak sebagai duplikat
     * @param bool $terbaru  true bila ini pembacaan paling baru untuk drum itu
     *                       (hanya baris seperti ini yang boleh mengubah peringatan)
     * @param array<string, mixed> $baris kolom yang disimpan
     */
    public function __construct(
        public readonly bool $disimpan,
        public readonly bool $terbaru = false,
        public readonly bool $late = false,
        public readonly array $baris = [],
    ) {
    }
}
