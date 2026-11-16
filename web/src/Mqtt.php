<?php

declare(strict_types=1);

namespace Silase;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

final class Mqtt
{
    private const SKEMA = [
        'mqtt' => ['tls' => false, 'ws' => false, 'port' => 1883],
        'mqtts' => ['tls' => true, 'ws' => false, 'port' => 8883],
        'ws' => ['tls' => false, 'ws' => true, 'port' => 80],
        'wss' => ['tls' => true, 'ws' => true, 'port' => 443],
    ];

    /**
     * Mengurai MQTT_URL, mis. wss://mqtt.ir1lab.id:443 atau mqtt://127.0.0.1:1883.
     *
     * @return array{host: string, port: int, tls: bool, ws: bool, jalur: string}
     */
    public static function uraiUrl(string $url): array
    {
        $u = parse_url($url);
        $skema = strtolower($u['scheme'] ?? '');
        if ($u === false || !isset(self::SKEMA[$skema]) || empty($u['host'])) {
            throw new \RuntimeException(
                "MQTT_URL tidak valid: \"$url\" (contoh: wss://mqtt.contoh.id:443 atau mqtt://127.0.0.1:1883)"
            );
        }
        $s = self::SKEMA[$skema];
        return [
            'host' => $u['host'],
            'port' => (int) ($u['port'] ?? $s['port']),
            'tls' => $s['tls'],
            'ws' => $s['ws'],
            'jalur' => ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : ''),
        ];
    }

    /** Klien yang sudah tersambung ke broker dari .env. */
    public static function sambung(string $clientId, ?string $user, ?string $pass, bool $sesiBersih): MqttClient
    {
        $t = self::uraiUrl(Config::wajib('MQTT_URL'));

        $pengaturan = (new ConnectionSettings())
            ->setUsername($user)
            ->setPassword($pass)
            ->setConnectTimeout(10)
            ->setKeepAliveInterval(30)
            ->setUseTls($t['tls']);
        if ($t['tls'] && Config::get('MQTT_CA_FILE') !== null) {
            $pengaturan = $pengaturan->setTlsCertificateAuthorityFile(Config::get('MQTT_CA_FILE'));
        }

        if ($t['ws']) {
            $klien = new WsMqttClient($t['host'], $t['port'], $clientId, MqttClient::MQTT_3_1_1);
            $klien->aturJalur($t['jalur']);
        } else {
            $klien = new MqttClient($t['host'], $t['port'], $clientId, MqttClient::MQTT_3_1_1);
        }
        $klien->connect($pengaturan, $sesiBersih);
        return $klien;
    }

    public static function akarTopik(): string
    {
        return Config::get('MQTT_TOPIC_ROOT', 'silase-ews');
    }
}
