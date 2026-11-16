<?php

declare(strict_types=1);

namespace Silase\Tests;

use Silase\Notifikasi\PunyaQr;
use Silase\Notifikasi\WhatsAppNotifier;

/** Gateway tiruan yang bisa menampilkan kode QR. */
final class WaQrTiruan implements WhatsAppNotifier, PunyaQr
{
    public bool $ok = false;
    public int $disiapkan = 0;

    public function nama(): string
    {
        return 'gateway';
    }

    public function send(string $nomor, string $jenis, array $data): void
    {
    }

    public function status(): array
    {
        return ['ok' => $this->ok, 'status' => $this->ok ? 'WORKING' : 'SCAN_QR_CODE'];
    }

    public function siapkan(): void
    {
        $this->disiapkan++;
    }

    public function qr(): ?string
    {
        return $this->ok ? null : "\x89PNG-tiruan";
    }
}
