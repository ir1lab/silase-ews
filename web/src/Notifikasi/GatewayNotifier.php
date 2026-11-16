<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

/**
 * Gateway WhatsApp tidak resmi yang di-hosting sendiri (WAHA). Hanya dipanggil
 * lewat jaringan lokal dan dilindungi kunci API. Pesan berupa teks biasa.
 */
final class GatewayNotifier implements WhatsAppNotifier, PunyaQr
{
    public function __construct(
        private HttpKlien $http,
        private string $url,
        private string $kunci,
        private string $sesi = 'default',
    ) {
        $this->url = rtrim($url, '/');
    }

    public function nama(): string
    {
        return 'gateway';
    }

    public function send(string $nomor, string $jenis, array $data): void
    {
        $badan = json_encode([
            'session' => $this->sesi,
            'chatId' => $nomor . '@c.us',
            'text' => $data['teks'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            [$kode, $jawab] = $this->http->kirim('POST', $this->url . '/api/sendText', $this->kepala(), $badan);
        } catch (HttpGagal $e) {
            throw new GatewayTerputus('gateway tidak terjangkau: ' . $e->getMessage());
        }
        if ($kode >= 200 && $kode < 300) {
            return;
        }
        $pesan = self::ringkas($jawab);
        // 5xx, atau 422 dari WAHA saat sesi belum WORKING: masalah sambungan, bukan isi pesan.
        if ($kode >= 500 || ($kode === 422 && stripos($jawab, 'session') !== false)) {
            throw new GatewayTerputus("gateway menjawab HTTP $kode: $pesan");
        }
        throw new KirimGagal("gateway menolak pesan (HTTP $kode): $pesan");
    }

    public function status(): array
    {
        try {
            [$kode, $jawab] = $this->http->kirim('GET', $this->url . '/api/sessions/' . rawurlencode($this->sesi), $this->kepala());
        } catch (HttpGagal) {
            return ['ok' => false, 'status' => 'TIDAK_TERJANGKAU'];
        }
        if ($kode === 404) {
            return ['ok' => false, 'status' => 'BELUM_DIBUAT'];
        }
        if ($kode === 401 || $kode === 403) {
            return ['ok' => false, 'status' => 'KUNCI_DITOLAK'];
        }
        $status = (string) (json_decode($jawab, true)['status'] ?? "HTTP_$kode");
        return ['ok' => $status === 'WORKING', 'status' => $status];
    }

    public function siapkan(): void
    {
        $status = $this->status()['status'];
        try {
            if ($status === 'BELUM_DIBUAT') {
                $this->http->kirim('POST', $this->url . '/api/sessions', $this->kepala(), json_encode(['name' => $this->sesi, 'start' => true]));
            } elseif ($status === 'FAILED' || $status === 'STOPPED') {
                $this->http->kirim('POST', $this->url . '/api/sessions/' . rawurlencode($this->sesi) . '/restart', $this->kepala());
            }
        } catch (HttpGagal) {
            // Dibiarkan: status berikutnya akan menunjukkan gateway tidak terjangkau.
        }
    }

    public function qr(): ?string
    {
        try {
            [$kode, $jawab] = $this->http->kirim(
                'GET',
                $this->url . '/api/' . rawurlencode($this->sesi) . '/auth/qr?format=image',
                ['X-Api-Key' => $this->kunci, 'Accept' => 'image/png'],
            );
        } catch (HttpGagal) {
            return null;
        }
        return ($kode === 200 && str_starts_with($jawab, "\x89PNG")) ? $jawab : null;
    }

    /** @return array<string, string> */
    private function kepala(): array
    {
        return ['X-Api-Key' => $this->kunci, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    private static function ringkas(string $jawab): string
    {
        $d = json_decode($jawab, true);
        $pesan = is_array($d) ? ($d['message'] ?? $d['error'] ?? null) : null;
        return mb_substr(is_string($pesan) ? $pesan : trim($jawab), 0, 150);
    }
}
