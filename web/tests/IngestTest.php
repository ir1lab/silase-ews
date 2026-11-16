<?php

declare(strict_types=1);

namespace Silase\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Silase\Catatan;
use Silase\Db;
use Silase\Ingest\Ingestor;
use Silase\Ingest\Penerima;
use Silase\Simulasi\PembuatPayload as P;

/**
 * Uji ingest tanpa broker dan tanpa perangkat keras.
 * Bawaan: SQLite di memori dari sql/schema.sql. Untuk menguji di MariaDB,
 * setel SILASE_UJI_MYSQL=1 dengan DB_* menunjuk ke basis data uji yang kosong.
 */
final class IngestTest extends TestCase
{
    private const KINI = 1794150000;        // waktu server pada uji
    private const TOPIK = 'silase-ews/node-01/data';

    private PDO $pdo;
    private Penerima $penerima;
    private int $kini = self::KINI;
    private string $dirLog;

    protected function setUp(): void
    {
        $skema = dirname(__DIR__) . '/sql/schema.sql';
        if (getenv('SILASE_UJI_MYSQL') === '1') {
            $this->pdo = Db::sambung();
            Db::pasangSkema($this->pdo, $skema);
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (['readings', 'devices'] as $tabel) {
                $this->pdo->exec("DELETE FROM $tabel");
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $this->pdo = Db::sqlite(':memory:', $skema);
        }
        $this->dirLog = sys_get_temp_dir() . '/silase-uji-' . bin2hex(random_bytes(4));
        mkdir($this->dirLog);
        $this->penerima = new Penerima(
            new Ingestor($this->pdo, fn (): int => $this->kini),
            new Catatan($this->dirLog),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->dirLog . '/payload-rusak.log');
        @rmdir($this->dirLog);
    }

    private function kirim(int $ts, array $ubah = [], string $topik = self::TOPIK): string
    {
        return $this->penerima->pesan($topik, P::json('node-01', $ts, $ubah));
    }

    /** @return list<array<string, mixed>> */
    private function baris(string $urut = 'id'): array
    {
        return $this->pdo->query("SELECT * FROM readings ORDER BY $urut")->fetchAll();
    }

    private function device(): array
    {
        return $this->pdo->query("SELECT * FROM devices WHERE device_id = 'node-01'")->fetch();
    }

    public function testPembacaanNormalDisimpanDanDiratakan(): void
    {
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(self::KINI, ['seq' => 12, 'uptime_s' => 3600]));

        $b = $this->baris()[0];
        $this->assertSame('node-01', $b['device_id']);
        $this->assertEquals(12, $b['seq']);
        $this->assertEquals(3600, $b['uptime_s']);
        $this->assertEquals(self::KINI, $b['ts']);
        $this->assertEquals(1, $b['ts_valid']);
        $this->assertEquals(0, $b['late']);
        $this->assertSame('2026-11-08 15:00:00', $b['measured_at']);   // UTC
        $this->assertSame('2026-11-08 15:00:00', $b['received_at']);
        $this->assertEquals(29.4, $b['t_tengah']);
        $this->assertEquals(28.5, $b['t_ambien']);
        $this->assertEquals(8450, $b['co2_ppm']);
        $this->assertEquals(96.2, $b['hs_rh']);
        $this->assertSame('NORMAL', $b['level']);
        $this->assertEquals(0.9, $b['dt']);
        $this->assertSame('tengah', $b['hotspot']);
        $this->assertEquals(0, $b['co2_leak']);
        $this->assertEquals(0, $b['stabilizing']);
        $this->assertSame('fermentasi stabil', $b['reason']);
        $this->assertEquals(0, $b['sensor_flags']);

        // Drum yang belum dikenal didaftarkan otomatis.
        $d = $this->device();
        $this->assertSame('node-01', $d['nama']);
        $this->assertSame('1.0.0', $d['fw']);
        $this->assertEquals(self::KINI, $d['last_ts']);
        $this->assertSame('2026-11-08 15:00:00', $d['last_seen']);
    }

    public function testContohPayloadDariReadmeFirmwareDiterima(): void
    {
        $json = '{"device_id":"node-01","fw":"1.0.0","seq":12,"ts":1794150000,"uptime_s":3600,
 "temp":{"bawah":29.81,"tengah":31.06,"atas":30.44,"ambien":28.5},
 "headspace":{"co2_ppm":8450,"temp":29.9,"rh":96.2},
 "ews":{"level":"NORMAL","dT":2.56,"hotspot":"tengah","co2_leak":false,
        "co2_max":9120,"stabilizing":true,"cycle_h":1.0,"reason":"fase stabilisasi"},
 "sensor_flags":0}';
        $this->assertSame(Penerima::DISIMPAN, $this->penerima->pesan(self::TOPIK, $json));
        $b = $this->baris()[0];
        $this->assertEquals(2.56, $b['dt']);
        $this->assertEquals(1, $b['stabilizing']);
        $this->assertEquals(1.0, $b['cycle_h']);
    }

    public function testDuplikatDitolak(): void
    {
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(self::KINI));
        $this->assertSame(Penerima::DUPLIKAT, $this->kirim(self::KINI));
        // Kiriman ulang dari buffer dengan isi berbeda tetap duplikat: kuncinya (device_id, ts).
        $this->assertSame(Penerima::DUPLIKAT, $this->kirim(self::KINI, ['dT' => 4.0]));

        $this->assertCount(1, $this->baris());
        $this->assertEquals(0.9, $this->baris()[0]['dt']);
    }

    public function testTsSamaDariDrumLainBukanDuplikat(): void
    {
        $this->kirim(self::KINI);
        $hasil = $this->penerima->pesan('silase-ews/node-02/data', P::json('node-02', self::KINI));
        $this->assertSame(Penerima::DISIMPAN, $hasil);
        $this->assertCount(2, $this->baris());
    }

    public function testDataTerlambatDitandaiDanTidakMenggeserYangTerbaru(): void
    {
        $ingestor = new Ingestor($this->pdo, fn (): int => $this->kini);

        $baru = $ingestor->terimaData('node-01', P::json('node-01', self::KINI - 5));
        $this->assertTrue($baru->terbaru);
        $this->assertFalse($baru->late);

        // Sisa buffer offline datang setelah pembacaan terbaru, berumur 1 jam.
        $lama = $ingestor->terimaData('node-01', P::json('node-01', self::KINI - 3600));
        $this->assertTrue($lama->disimpan);
        $this->assertTrue($lama->late);
        $this->assertFalse($lama->terbaru);

        $b = $this->baris('ts');
        $this->assertEquals(1, $b[0]['late']);
        $this->assertSame('2026-11-08 14:00:00', $b[0]['measured_at']);
        $this->assertSame('2026-11-08 15:00:00', $b[0]['received_at']);
        $this->assertEquals(0, $b[1]['late']);
        $this->assertEquals(self::KINI - 5, $this->device()['last_ts']);
    }

    public function testBufferOfflineBerurutanTetapDianggapTerbaru(): void
    {
        $ingestor = new Ingestor($this->pdo, fn (): int => $this->kini);
        $ingestor->terimaData('node-01', P::json('node-01', self::KINI - 7200));

        // Node pulih: buffer dikirim dari yang tertua, semuanya lebih baru dari last_ts.
        foreach ([3600, 3300, 3000] as $umur) {
            $h = $ingestor->terimaData('node-01', P::json('node-01', self::KINI - $umur));
            $this->assertTrue($h->late);
            $this->assertTrue($h->terbaru);
        }
    }

    public function testBatasTerlambatSepuluhMenit(): void
    {
        $this->kirim(self::KINI - 600);
        $this->kirim(self::KINI - 601);
        $b = $this->baris('ts DESC');
        $this->assertEquals(0, $b[0]['late']);
        $this->assertEquals(1, $b[1]['late']);
    }

    public function testTsNolMemakaiWaktuTerimaDanDitandai(): void
    {
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(0, ['seq' => 3, 'uptime_s' => 615]));

        $b = $this->baris()[0];
        $this->assertEquals(0, $b['ts']);
        $this->assertEquals(0, $b['ts_valid']);
        $this->assertEquals(0, $b['late']);
        $this->assertSame('2026-11-08 15:00:00', $b['measured_at']);
        $this->assertEquals(0, $this->device()['last_ts']);
    }

    public function testTsNolDuplikatMemakaiSeqDanUptime(): void
    {
        $this->kirim(0, ['seq' => 3, 'uptime_s' => 615]);
        $this->kini += 30;
        $this->assertSame(Penerima::DUPLIKAT, $this->kirim(0, ['seq' => 3, 'uptime_s' => 615]));
        // seq berikutnya, atau seq sama dengan uptime berbeda (node sudah restart), bukan duplikat.
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(0, ['seq' => 4, 'uptime_s' => 915]));
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(0, ['seq' => 3, 'uptime_s' => 20]));
        $this->assertCount(3, $this->baris());
    }

    public function testNilaiNullDisimpanSebagaiNullBukanNol(): void
    {
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(self::KINI, ['null' => ['tengah', 'scd41']]));

        $b = $this->baris()[0];
        $this->assertNull($b['t_tengah']);
        $this->assertNull($b['co2_ppm']);
        $this->assertNull($b['hs_temp']);
        $this->assertNull($b['hs_rh']);
        $this->assertNotNull($b['t_bawah']);
        $this->assertEquals(18, $b['sensor_flags']);      // bit1 tengah + bit4 SCD41
        $this->assertSame('atas', $b['hotspot']);
    }

    public function testSemuaSuhuNullMembuatDtDanHotspotNull(): void
    {
        $this->kirim(self::KINI, ['null' => ['bawah', 'tengah', 'atas', 'ambien']]);
        $b = $this->baris()[0];
        $this->assertNull($b['dt']);
        $this->assertNull($b['hotspot']);
        $this->assertEquals(15, $b['sensor_flags']);
    }

    public function testPayloadRusakDicatatLaluDibuang(): void
    {
        $baik = P::data('node-01', self::KINI);
        $rusak = [
            'bukan JSON' => '{"device_id":"node-01","ts":',
            'bukan objek' => '[1,2,3]',
            'kosong' => '',
            'level asing' => json_encode(array_replace_recursive($baik, ['ews' => ['level' => 'GAWAT']])),
            'temp hilang' => json_encode(array_diff_key($baik, ['temp' => 1])),
            'suhu berupa teks' => json_encode(array_replace_recursive($baik, ['temp' => ['atas' => '30.1']])),
            'ts negatif' => json_encode(['ts' => -5] + $baik),
            'ts pecahan' => json_encode(['ts' => 1.5] + $baik),
            'ts masa depan' => json_encode(['ts' => self::KINI + 90000] + $baik),
            'device beda dari topik' => json_encode(['device_id' => 'node-99'] + $baik),
            'co2_leak bukan boolean' => json_encode(array_replace_recursive($baik, ['ews' => ['co2_leak' => 'ya']])),
        ];
        foreach ($rusak as $nama => $payload) {
            $this->assertSame(Penerima::RUSAK, $this->penerima->pesan(self::TOPIK, $payload), $nama);
        }
        $this->assertCount(0, $this->baris());

        $log = file($this->dirLog . '/payload-rusak.log', FILE_IGNORE_NEW_LINES);
        $this->assertCount(count($rusak), $log);
        $this->assertSame(self::TOPIK, json_decode($log[0], true)['topik']);

        // Worker tetap menerima data berikutnya.
        $this->assertSame(Penerima::DISIMPAN, $this->kirim(self::KINI));
    }

    public function testTopikLainDiabaikan(): void
    {
        $json = P::json('node-01', self::KINI);
        $this->assertSame(Penerima::DIABAIKAN, $this->penerima->pesan('silase-ews/node-01/cmd', 'mute'));
        $this->assertSame(Penerima::DIABAIKAN, $this->penerima->pesan('lain/node-01/data', $json));
        $this->assertSame(Penerima::DIABAIKAN, $this->penerima->pesan('silase-ews/node-01/data/x', $json));
        $this->assertCount(0, $this->baris());
    }

    public function testStatusDanLastWill(): void
    {
        $topik = 'silase-ews/node-01/status';
        $status = P::status('WASPADA', 'kenaikan suhu internal', self::KINI);
        $this->assertSame(Penerima::STATUS, $this->penerima->pesan($topik, $status));

        $d = $this->device();
        $this->assertEquals(1, $d['online']);
        $this->assertSame('WASPADA', $d['status_level']);
        $this->assertSame('kenaikan suhu internal', $d['status_reason']);
        $this->assertNull($d['last_seen']);     // status retained bukan bukti data baru

        // Last will hanya membawa online:false; level terakhir dipertahankan.
        $this->assertSame(Penerima::STATUS, $this->penerima->pesan($topik, '{"online":false}'));
        $d = $this->device();
        $this->assertEquals(0, $d['online']);
        $this->assertSame('WASPADA', $d['status_level']);

        $this->assertSame(Penerima::RUSAK, $this->penerima->pesan($topik, '{"online":"ya"}'));
    }

    public function testSkemaMemuatSemuaTabel(): void
    {
        if (getenv('SILASE_UJI_MYSQL') === '1') {
            $ada = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $ada = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        $wajib = [
            'users', 'devices', 'readings', 'cycles', 'alerts',
            'alert_recipients', 'notifications', 'commands_log', 'pengaturan',
        ];
        $this->assertSame([], array_values(array_diff($wajib, $ada)));
    }
}
