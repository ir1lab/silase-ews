<?php

declare(strict_types=1);

use Silase\Config;
use Silase\Db;
use Silase\Masuk\GoogleSso;
use Silase\Notifikasi\CurlKlien;
use Silase\Notifikasi\PabrikNotifier;
use Silase\Perintah\MqttPerintah;
use Silase\Web\Aplikasi;

// Server bawaan PHP (php -S): berkas statis dilayani langsung.
if (PHP_SAPI === 'cli-server') {
    $berkas = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($berkas)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

Config::muat(dirname(__DIR__));
Aplikasi::buat(
    Db::sambung(),
    null,
    Config::benar('APP_DEBUG'),
    PabrikNotifier::dariEnv(),
    null,
    Config::get('MQTT_URL') !== null ? new MqttPerintah() : null,
    GoogleSso::dariEnv(new CurlKlien()),
)->run();
