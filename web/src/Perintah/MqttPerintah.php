<?php

declare(strict_types=1);

namespace Silase\Perintah;

use Silase\Config;
use Silase\Mqtt;

/**
 * Perintah lewat broker MQTT dengan akun web (kredensial hanya di server).
 * Dikirim QoS 1 supaya broker mengonfirmasi penerimaannya. Firmware sendiri
 * tidak membalas, jadi "terkirim" berarti sampai di broker, belum tentu di node.
 */
final class MqttPerintah implements PengirimPerintah
{
    public function kirim(string $deviceId, string $perintah): void
    {
        if (!isset(self::PERINTAH[$perintah])) {
            throw new \RuntimeException('perintah tidak dikenal');
        }
        try {
            $klien = Mqtt::sambung(
                'silase-web-cmd-' . bin2hex(random_bytes(4)),
                Config::get('MQTT_USER'),
                Config::get('MQTT_PASS'),
                true,
            );
            $klien->publish(Mqtt::akarTopik() . "/$deviceId/cmd", $perintah, 1);
            $klien->loop(true, true, 5);          // tunggu konfirmasi broker, paling lama 5 detik
            $klien->disconnect();
        } catch (\Throwable $e) {
            throw new \RuntimeException('broker tidak menerima perintah: ' . $e->getMessage(), 0, $e);
        }
    }
}
