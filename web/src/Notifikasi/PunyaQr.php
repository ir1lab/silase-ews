<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

/** Provider yang ditautkan dengan memindai kode QR (gateway). */
interface PunyaQr
{
    /** Memastikan sesi berjalan supaya kode QR tersedia. */
    public function siapkan(): void;

    /** @return string|null isi gambar PNG, atau null bila tidak ada QR (mis. sudah tertaut) */
    public function qr(): ?string;
}
