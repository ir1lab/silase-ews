<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

/**
 * WhatsApp Cloud API resmi (Meta). Pesan dikirim sebagai template yang sudah
 * disetujui; lima parameter badannya: nama drum, level, dT, alasan, waktu.
 * Disiapkan untuk dipakai setelah layanan stabil; diaktifkan lewat WA_PROVIDER=cloud_api.
 */
final class CloudApiNotifier implements WhatsAppNotifier
{
    public function __construct(
        private HttpKlien $http,
        private string $token,
        private string $idNomor,
        private string $template,
        private string $bahasa = 'id',
        private string $versi = 'v21.0',
    ) {
    }

    public function nama(): string
    {
        return 'cloud_api';
    }

    public function send(string $nomor, string $jenis, array $data): void
    {
        $parameter = array_map(fn (string $t): array => ['type' => 'text', 'text' => $t], $data['parameter']);
        $badan = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $nomor,
            'type' => 'template',
            'template' => [
                'name' => $this->template,
                'language' => ['code' => $this->bahasa],
                'components' => [['type' => 'body', 'parameters' => $parameter]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            [$kode, $jawab] = $this->http->kirim(
                'POST',
                "https://graph.facebook.com/{$this->versi}/{$this->idNomor}/messages",
                ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json'],
                $badan,
            );
        } catch (HttpGagal $e) {
            throw new GatewayTerputus('Cloud API tidak terjangkau: ' . $e->getMessage());
        }
        if ($kode >= 200 && $kode < 300) {
            return;
        }
        $pesan = mb_substr((string) (json_decode($jawab, true)['error']['message'] ?? trim($jawab)), 0, 150);
        if ($kode >= 500 || $kode === 429) {
            throw new GatewayTerputus("Cloud API menjawab HTTP $kode: $pesan");
        }
        throw new KirimGagal("Cloud API menolak pesan (HTTP $kode): $pesan");
    }

    /** Cloud API tidak punya sesi yang bisa terputus; cukup dianggap siap bila terkonfigurasi. */
    public function status(): array
    {
        return ['ok' => true, 'status' => 'CLOUD_API'];
    }
}
