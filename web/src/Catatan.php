<?php

declare(strict_types=1);

namespace Silase;

/** Log sederhana: baris ke stderr (journal systemd) dan berkas untuk payload rusak. */
final class Catatan
{
    /** @param resource|null $aliran */
    public function __construct(private ?string $dirLog = null, private $aliran = null)
    {
    }

    public function info(string $pesan): void
    {
        $this->tulis('INFO', $pesan);
    }

    public function galat(string $pesan): void
    {
        $this->tulis('GALAT', $pesan);
    }

    /** Payload yang ditolak dicatat utuh (dipotong 2 KB) agar bisa ditelusuri. */
    public function payloadRusak(string $topik, string $alasan, string $payload): void
    {
        $this->tulis('RUSAK', "$topik: $alasan");
        if ($this->dirLog === null) {
            return;
        }
        $baris = json_encode([
            'waktu' => gmdate('c'),
            'topik' => $topik,
            'alasan' => $alasan,
            'payload' => mb_strcut($payload, 0, 2048),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        @file_put_contents($this->dirLog . '/payload-rusak.log', $baris . "\n", FILE_APPEND | LOCK_EX);
    }

    private function tulis(string $tingkat, string $pesan): void
    {
        if ($this->aliran === null) {
            return;
        }
        fwrite($this->aliran, sprintf("%s [%s] %s\n", gmdate('Y-m-d\TH:i:s\Z'), $tingkat, $pesan));
    }
}
