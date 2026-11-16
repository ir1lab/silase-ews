<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

/** Gateway tidak terjangkau atau sesi WhatsApp terputus; pesan tetap di antrean. */
final class GatewayTerputus extends \RuntimeException
{
}
