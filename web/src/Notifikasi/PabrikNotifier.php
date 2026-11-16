<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

use Silase\Config;

final class PabrikNotifier
{
    /** Provider yang dicatat pada antrean walaupun pengirimnya belum dikonfigurasi. */
    public static function namaProvider(): string
    {
        return Config::get('WA_PROVIDER', 'gateway') === 'cloud_api' ? 'cloud_api' : 'gateway';
    }

    /** @return WhatsAppNotifier|null null bila WhatsApp belum dikonfigurasi di .env */
    public static function dariEnv(?HttpKlien $http = null): ?WhatsAppNotifier
    {
        $http ??= new CurlKlien();
        if (self::namaProvider() === 'cloud_api') {
            $token = Config::get('WA_CLOUD_TOKEN');
            $id = Config::get('WA_CLOUD_PHONE_ID');
            $template = Config::get('WA_CLOUD_TEMPLATE');
            if ($token === null || $id === null || $template === null) {
                return null;
            }
            return new CloudApiNotifier(
                $http,
                $token,
                $id,
                $template,
                Config::get('WA_CLOUD_LANG', 'id'),
                Config::get('WA_CLOUD_API_VERSION', 'v21.0'),
            );
        }
        $url = Config::get('WA_GATEWAY_URL');
        $kunci = Config::get('WA_GATEWAY_KEY');
        if ($url === null || $kunci === null) {
            return null;
        }
        return new GatewayNotifier($http, $url, $kunci, Config::get('WA_GATEWAY_SESSION', 'default'));
    }
}
