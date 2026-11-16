<?php

declare(strict_types=1);

namespace Silase\Web;

use Psr\Http\Message\ResponseInterface;

/** Templat PHP polos dengan satu kerangka halaman. */
final class Tampilan
{
    /** @param (\Closure(): array<string, mixed>)|null $umum data yang dipakai kerangka di semua halaman */
    public function __construct(private string $dir, private ?\Closure $umum = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function halaman(ResponseInterface $respons, string $templat, array $data, int $kode = 200): ResponseInterface
    {
        $data += $this->umum ? ($this->umum)() : [];
        $isi = $this->potong($templat, $data);
        $html = $this->potong('kerangka', $data + ['isi' => $isi]);
        $respons->getBody()->write($html);
        return $respons->withStatus($kode)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param array<string, mixed> $data */
    private function potong(string $templat, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $this->dir . '/' . $templat . '.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
