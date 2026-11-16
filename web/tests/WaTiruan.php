<?php

declare(strict_types=1);

namespace Silase\Tests;

use Silase\Notifikasi\WhatsAppNotifier;

/** Pengirim WhatsApp tiruan: mencatat kiriman, bisa disetel gagal. */
final class WaTiruan implements WhatsAppNotifier
{
    /** @var list<array{nomor: string, jenis: string, data: array}> */
    public array $terkirim = [];
    public ?\Throwable $lempar = null;
    public bool $ok = true;
    public int $panggilan = 0;
    public int $cekStatus = 0;

    public function nama(): string
    {
        return 'gateway';
    }

    public function send(string $nomor, string $jenis, array $data): void
    {
        $this->panggilan++;
        if ($this->lempar !== null) {
            throw $this->lempar;
        }
        $this->terkirim[] = ['nomor' => $nomor, 'jenis' => $jenis, 'data' => $data];
    }

    public function status(): array
    {
        $this->cekStatus++;
        return ['ok' => $this->ok, 'status' => $this->ok ? 'WORKING' : 'SCAN_QR_CODE'];
    }
}
