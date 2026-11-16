<?php

declare(strict_types=1);

namespace Silase\Notifikasi;

use Silase\Config;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/** Email ke admin lewat SMTP (MAILER_DSN, MAIL_FROM, ADMIN_EMAIL di .env). */
final class SurelSmtp implements PengirimSurel
{
    /** @param list<string> $tujuan */
    public function __construct(private string $dsn, private string $dari, private array $tujuan)
    {
    }

    /** @return self|null null bila email belum dikonfigurasi */
    public static function dariEnv(): ?self
    {
        $dsn = Config::get('MAILER_DSN');
        $tujuan = array_values(array_filter(array_map('trim', explode(',', (string) Config::get('ADMIN_EMAIL', '')))));
        if ($dsn === null || $tujuan === []) {
            return null;
        }
        return new self($dsn, Config::get('MAIL_FROM', $tujuan[0]), $tujuan);
    }

    public function kirim(string $subjek, string $isi): void
    {
        $email = (new Email())->from($this->dari)->to(...$this->tujuan)->subject($subjek)->text($isi);
        (new Mailer(Transport::fromDsn($this->dsn)))->send($email);
    }
}
