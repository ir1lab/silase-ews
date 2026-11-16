<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

final class CurlKlien implements HttpKlien
{
    public function __construct(private int $batasDetik = 15)
    {
    }

    public function kirim(string $metode, string $url, array $kepala = [], ?string $badan = null): array
    {
        $baris = [];
        foreach ($kepala as $nama => $nilai) {
            $baris[] = "$nama: $nilai";
        }
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => $metode,
            CURLOPT_HTTPHEADER => $baris,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->batasDetik,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($badan !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $badan);
        }
        $jawab = curl_exec($c);
        if ($jawab === false) {
            $galat = curl_error($c);
            curl_close($c);
            throw new HttpGagal($galat !== '' ? $galat : 'permintaan gagal');
        }
        $kode = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return [$kode, (string) $jawab];
    }
}
