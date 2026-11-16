<?php

declare(strict_types=1);

namespace Silase;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\MqttClient;

/**
 * MQTT lewat WebSocket (ws:// atau wss://), subprotokol "mqtt" (RFC 6455).
 *
 * php-mqtt/client hanya mengenal TCP. Kelas ini memakai soket yang sama, lalu
 * menambahkan handshake HTTP Upgrade dan membungkus setiap baca/tulis dalam
 * frame WebSocket, sehingga sisa pustaka bekerja tanpa perubahan.
 */
final class WsMqttClient extends MqttClient
{
    private string $jalur = '/';
    private int $batasWaktu = 10;
    /** Byte mentah dari soket yang belum membentuk frame utuh. */
    private string $mentah = '';
    /** Isi frame data yang sudah dibuka, menunggu dibaca pustaka. */
    private string $isi = '';
    private bool $ditutup = false;

    public function aturJalur(string $jalur): void
    {
        $this->jalur = $jalur === '' ? '/' : $jalur;
    }

    public function connect(?ConnectionSettings $settings = null, bool $useCleanSession = false): void
    {
        $this->batasWaktu = ($settings ?? new ConnectionSettings())->getConnectTimeout();
        parent::connect($settings, $useCleanSession);
    }

    protected function establishSocketConnection(): void
    {
        parent::establishSocketConnection();
        $this->mentah = $this->isi = '';
        $this->ditutup = false;

        $kunci = base64_encode(random_bytes(16));
        $permintaan = "GET {$this->jalur} HTTP/1.1\r\n"
            . 'Host: ' . $this->getHost() . ':' . $this->getPort() . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $kunci\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: mqtt\r\n"
            . "User-Agent: silase-ews-web\r\n\r\n";

        try {
            parent::writeToSocket($permintaan);
            $jawaban = '';
            $mulai = microtime(true);
            while (($akhir = strpos($jawaban, "\r\n\r\n")) === false) {
                if (microtime(true) - $mulai > $this->batasWaktu || strlen($jawaban) > 16384) {
                    throw $this->gagalSambung('tidak ada jawaban handshake WebSocket');
                }
                $this->tunggu(0.2);
                $jawaban .= parent::readFromSocket(self::SOCKET_READ_BUFFER_SIZE, true);
            }
        } catch (DataTransferException $e) {
            throw $this->gagalSambung('sambungan putus saat handshake WebSocket');
        }

        $kepala = substr($jawaban, 0, $akhir);
        $this->mentah = substr($jawaban, $akhir + 4);

        if (!preg_match('#^HTTP/1\.1 101\b#', $kepala)) {
            throw $this->gagalSambung('server menolak WebSocket: ' . strtok($kepala, "\r\n"));
        }
        $harapan = base64_encode(sha1($kunci . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $kepala, $m) || $m[1] !== $harapan) {
            throw $this->gagalSambung('Sec-WebSocket-Accept tidak cocok');
        }
    }

    protected function writeToSocket(string $data, ?int $length = null): void
    {
        $panjang = min($length ?? strlen($data), strlen($data));
        parent::writeToSocket(self::frame(0x2, substr($data, 0, $panjang)));
    }

    /**
     * Penutupan yang rapi: setelah DISCONNECT MQTT, kirim frame close WebSocket dan
     * tunggu server menutup. Tanpa ini soket langsung diputus, dan proxy di depan
     * broker (Cloudflare) bisa membuang pesan terakhir yang belum sempat diteruskan.
     */
    protected function sendDisconnect(): void
    {
        parent::sendDisconnect();
        try {
            parent::writeToSocket(self::frame(0x8, pack('n', 1000)));
            $mulai = microtime(true);
            while (!$this->ditutup && microtime(true) - $mulai < 2.0) {
                $this->tunggu(0.1);
                $this->serap();
            }
        } catch (DataTransferException) {
            // Server sudah menutup sambungan; itu yang ditunggu.
        }
    }

    protected function readFromSocket(int $limit = self::SOCKET_READ_BUFFER_SIZE, bool $withoutBlocking = false): string
    {
        $this->serap();

        if (!$withoutBlocking) {
            // Pustaka meminta tepat $limit byte; tunggu sampai frame yang memuatnya tiba.
            $mulai = microtime(true);
            while (strlen($this->isi) < $limit) {
                if (microtime(true) - $mulai > $this->batasWaktu) {
                    throw new DataTransferException(
                        DataTransferException::EXCEPTION_RX_DATA,
                        'Batas waktu menunggu data WebSocket terlampaui.'
                    );
                }
                $this->tunggu(0.2);
                $this->serap();
            }
        }

        $hasil = substr($this->isi, 0, $limit);
        $this->isi = substr($this->isi, strlen($hasil));
        return $hasil;
    }

    /** Membaca semua byte yang tersedia dan membuka frame yang sudah utuh. */
    private function serap(): void
    {
        try {
            do {
                $potong = parent::readFromSocket(self::SOCKET_READ_BUFFER_SIZE, true);
                $this->mentah .= $potong;
            } while (strlen($potong) === self::SOCKET_READ_BUFFER_SIZE);
        } catch (DataTransferException $e) {
            // Sisa isi yang sudah diterima tetap diserahkan dulu sebelum galat.
            if ($this->isi === '') {
                throw $e;
            }
        }

        while (($f = self::bukaFrame($this->mentah)) !== null) {
            [$opcode, $muatan, $terpakai] = $f;
            $this->mentah = substr($this->mentah, $terpakai);
            match ($opcode) {
                0x0, 0x1, 0x2 => $this->isi .= $muatan,                         // lanjutan, teks, biner
                0x9 => parent::writeToSocket(self::frame(0xA, $muatan)),        // ping -> pong
                0x8 => $this->ditutup = true,
                default => null,                                                // pong dan lainnya
            };
        }

        if ($this->ditutup && $this->isi === '') {
            throw new DataTransferException(
                DataTransferException::EXCEPTION_RX_DATA,
                'Server menutup sambungan WebSocket.'
            );
        }
    }

    /** Frame klien selalu ber-mask (RFC 6455 bagian 5.3). */
    private static function frame(int $opcode, string $muatan): string
    {
        $n = strlen($muatan);
        $kepala = chr(0x80 | $opcode);
        if ($n < 126) {
            $kepala .= chr(0x80 | $n);
        } elseif ($n < 65536) {
            $kepala .= chr(0x80 | 126) . pack('n', $n);
        } else {
            $kepala .= chr(0x80 | 127) . pack('J', $n);
        }
        $mask = random_bytes(4);
        return $kepala . $mask . ($muatan ^ str_pad('', $n, $mask));
    }

    /** @return array{int, string, int}|null [opcode, muatan, byte terpakai]; null bila frame belum utuh */
    private static function bukaFrame(string $buf): ?array
    {
        $ada = strlen($buf);
        if ($ada < 2) {
            return null;
        }
        $opcode = ord($buf[0]) & 0x0F;
        $bermask = (ord($buf[1]) & 0x80) !== 0;
        $n = ord($buf[1]) & 0x7F;
        $pos = 2;
        if ($n === 126) {
            if ($ada < 4) {
                return null;
            }
            $n = unpack('n', substr($buf, 2, 2))[1];
            $pos = 4;
        } elseif ($n === 127) {
            if ($ada < 10) {
                return null;
            }
            $n = unpack('J', substr($buf, 2, 8))[1];
            $pos = 10;
        }
        $mask = '';
        if ($bermask) {
            if ($ada < $pos + 4) {
                return null;
            }
            $mask = substr($buf, $pos, 4);
            $pos += 4;
        }
        if ($ada < $pos + $n) {
            return null;
        }
        $muatan = substr($buf, $pos, $n);
        if ($bermask) {
            $muatan ^= str_pad('', $n, $mask);
        }
        return [$opcode, $muatan, $pos + $n];
    }

    private function tunggu(float $detik): void
    {
        if (!is_resource($this->socket)) {
            return;
        }
        $baca = [$this->socket];
        $tulis = $lain = null;
        @stream_select($baca, $tulis, $lain, 0, (int) ($detik * 1_000_000));
    }

    private function gagalSambung(string $pesan): ConnectingToBrokerFailedException
    {
        return new ConnectingToBrokerFailedException(
            ConnectingToBrokerFailedException::EXCEPTION_CONNECTION_FAILED,
            'WebSocket: ' . $pesan
        );
    }
}
