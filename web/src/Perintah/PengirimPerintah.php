<?php

declare(strict_types=1);

namespace Silase\Perintah;

interface PengirimPerintah
{
    public const PERINTAH = [
        'start_cycle' => 'Mulai siklus baru',
        'sample_now' => 'Ukur sekarang',
        'mute' => 'Bisukan buzzer',
    ];

    /**
     * Mengirim perintah teks polos ke topik cmd node.
     *
     * @throws \RuntimeException bila perintah tidak sampai ke broker
     */
    public function kirim(string $deviceId, string $perintah): void;
}
