<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

interface HttpKlien
{
    /**
     * @param array<string, string> $kepala
     * @return array{int, string} kode status dan badan jawaban
     * @throws HttpGagal bila permintaan tidak sampai ke server
     */
    public function kirim(string $metode, string $url, array $kepala = [], ?string $badan = null): array;
}
