<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

/** Pengirim WhatsApp. Implementasi dipilih lewat WA_PROVIDER di .env. */
interface WhatsAppNotifier
{
    /** Nama provider seperti yang dicatat di tabel notifications: gateway | cloud_api. */
    public function nama(): string;

    /**
     * @param string $nomor format 628xxx
     * @param string $jenis peringatan | pengingat | pulih | uji
     * @param array{teks: string, parameter: array{string, string, string, string, string}} $data
     * @throws GatewayTerputus bila layanan tidak tersambung (pesan tetap di antrean)
     * @throws KirimGagal bila pesan ditolak (dihitung sebagai satu percobaan)
     */
    public function send(string $nomor, string $jenis, array $data): void;

    /** @return array{ok: bool, status: string} keadaan sambungan ke WhatsApp */
    public function status(): array;
}
