# SILASE-EWS Web

Dasbor web untuk node SILASE-EWS: menerima data lewat MQTT, menyimpannya, lalu
menampilkan kondisi fermentasi per drum. Format data mengikuti firmware v1.0
(`../src`, `../include`); web tidak mengubah firmware.

**Status: tahap (b) dari 4.** Yang sudah ada: skema basis data, worker ingest,
simulator node, halaman ringkasan dan detail drum, dan uji otomatis. Peringatan
WhatsApp menyusul di tahap (c); siklus, perintah, ekspor, dan login di tahap (d).

Halaman web belum memakai login (baru ada di tahap d). Sebelum itu, jangan
buka ke internet tanpa pembatasan akses.

## Kebutuhan

- PHP 8.2 atau lebih baru dengan ekstensi `pdo_mysql`, `mbstring`,
  `openssl`; `pcntl` dianjurkan agar worker berhenti dengan rapi.
- Composer.
- MariaDB 11 atau MySQL 8.
- Broker MQTT (Mosquitto).

## Pemasangan

```bash
cd web
composer install --no-dev      # tanpa --no-dev bila ingin menjalankan uji
cp .env.example .env           # lalu isi
```

### Basis data

Buat basis data dan penggunanya (jalankan sebagai root MariaDB; ganti kata sandinya):

```sql
CREATE DATABASE silase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'silase'@'%' IDENTIFIED BY 'ganti-kata-sandi-db';
GRANT ALL PRIVILEGES ON silase.* TO 'silase'@'%';
```

Isi `DB_*` di `.env`, lalu pasang skema (aman diulang):

```bash
php bin/migrasi.php
```

Semua waktu disimpan dalam UTC dan baru diubah ke Asia/Jakarta saat ditampilkan.

### Broker MQTT

Web memakai akun broker sendiri, terpisah dari akun node:

| Akun | Boleh publish | Boleh subscribe |
|---|---|---|
| `silase-node` (firmware) | `silase-ews/+/data`, `silase-ews/+/status` | `silase-ews/+/cmd` |
| `silase-web` (worker dan web) | `silase-ews/+/cmd` | `silase-ews/+/data`, `silase-ews/+/status` |

Cara tercepat: jalankan `deploy/broker/buat-akun.sh` di host broker. Skrip itu
membuat kedua peran dan akun, memeriksa hasilnya, lalu menampilkan kata sandi
acak yang siap disalin ke `.env`. Aman diulang.

Perintah manualnya, bila ingin mengerjakan sendiri (tiap `createClient`
menanyakan kata sandi):

```bash
mosquitto_ctrl -u admin dynsec createRole silase-node
mosquitto_ctrl -u admin dynsec addRoleACL silase-node publishClientSend 'silase-ews/+/data' allow
mosquitto_ctrl -u admin dynsec addRoleACL silase-node publishClientSend 'silase-ews/+/status' allow
mosquitto_ctrl -u admin dynsec addRoleACL silase-node subscribePattern 'silase-ews/+/cmd' allow
mosquitto_ctrl -u admin dynsec createClient silase-node
mosquitto_ctrl -u admin dynsec addClientRole silase-node silase-node

mosquitto_ctrl -u admin dynsec createRole silase-web
mosquitto_ctrl -u admin dynsec addRoleACL silase-web subscribePattern 'silase-ews/+/data' allow
mosquitto_ctrl -u admin dynsec addRoleACL silase-web subscribePattern 'silase-ews/+/status' allow
mosquitto_ctrl -u admin dynsec addRoleACL silase-web publishClientSend 'silase-ews/+/cmd' allow
mosquitto_ctrl -u admin dynsec createClient silase-web
mosquitto_ctrl -u admin dynsec addClientRole silase-web silase-web
```

Isi `MQTT_USER` dan `MQTT_PASS` di `.env` dengan akun `silase-web`. Simulator
berperan sebagai node, jadi isi `SIM_MQTT_USER` dan `SIM_MQTT_PASS` dengan akun
`silase-node`.

Alamat broker diisi di `MQTT_URL`. Skemanya menentukan jalur sambungan:

| Skema | Jalur | Port bawaan |
|---|---|---|
| `wss://` | MQTT lewat WebSocket dengan TLS | 443 |
| `ws://` | MQTT lewat WebSocket | 80 |
| `mqtts://` | TCP dengan TLS | 8883 |
| `mqtt://` | TCP | 1883 |

Broker lab dipakai lewat `MQTT_URL=wss://mqtt.ir1lab.id:443`. Jalur WebSocket
ditangani `src/WsMqttClient.php`, karena pustaka `php-mqtt/client` sendiri hanya
mengenal TCP.

Firmware v1.0 memakai PubSubClient lewat TCP dan belum bisa tersambung lewat
WebSocket. Selama broker hanya terbuka lewat `wss://`, node belum bisa mengirim
data; lihat bagian "Usulan untuk firmware".

## Menjalankan worker ingest

```bash
php bin/worker-ingest.php
```

Worker berlangganan `silase-ews/+/data` dan `silase-ews/+/status`, lalu:

- menolak duplikat dengan kunci `(device_id, ts)`; bila `ts = 0` kuncinya
  `(device_id, seq, uptime_s)`;
- untuk `ts = 0` memakai waktu terima server dan menandai baris (`ts_valid = 0`);
- menandai `late = 1` bila data diterima lebih dari 10 menit setelah `ts`;
- menyimpan nilai `null` sebagai `NULL`, tidak pernah nol;
- mendaftarkan drum baru secara otomatis (nama awal = `device_id`);
- mencatat payload rusak ke `storage/log/payload-rusak.log` lalu membuangnya;
- menyambung ulang sendiri bila broker atau basis data terputus.

Untuk produksi, jalankan lewat systemd dengan `deploy/silase-ingest.service`
(petunjuk ada di dalam berkasnya).

Firmware mengirim data dengan QoS 0. Bila worker mati, data yang lewat saat itu
tidak tersimpan dan tidak dikirim ulang oleh node, jadi worker harus selalu hidup.

## Menjalankan web

Untuk mencoba secara lokal:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Lalu buka `http://127.0.0.1:8080`. Untuk produksi, arahkan document root server
web (nginx atau Apache dengan PHP-FPM) ke folder `public/` dan teruskan semua
permintaan yang bukan berkas ke `public/index.php`.

- **Ringkasan** (`/`): satu kartu per drum, yang perlu diperiksa tampil paling
  atas. Abu-abu berarti offline (last will dari broker) atau tidak ada data
  selama lebih dari 15 menit. Halaman memuat ulang sendiri tiap menit.
- **Detail drum** (`/drum/<device_id>`): grafik suhu empat kanal, ΔT dengan
  garis ambang 2 dan 5 °C, CO₂ dan RH; rentang 24 jam, 7 hari, atau satu siklus;
  daftar sensor gagal; riwayat peringatan (terisi mulai tahap c).

"Satu siklus" dihitung dari umur siklus yang dilaporkan firmware (`cycle_h`)
pada pembacaan terbaru. Rentang yang lebih panjang dari 7 hari dirata-ratakan
per selang agar grafik tetap ringan di ponsel.

CSS dibangun dari kelas Tailwind di templat dan hasilnya (`public/assets/app.css`)
disimpan di git, jadi server tidak butuh Node. Setelah mengubah templat, bangun
ulang dengan `bash bin/bangun-css.sh` (butuh Node di mesin pengembang).

## Deploy ke ir1server

Aplikasi berjalan sebagai stack Docker `silase`: kontainer `silase-web`
(FrankenPHP, port 8088) dan worker `silase-ingest`. Basis datanya memakai
kontainer `mariadb` yang sudah ada di network docker `web`. Berkasnya ada di
`deploy/ir1server/`.

Jalankan di host ir1server (bukan dari dalam code-server):

```bash
cd /srv/projects/nextjs/silase/web/deploy/ir1server
bash pasang.sh
```

`pasang.sh` dipakai untuk pemasangan pertama maupun tiap pembaruan kode, dan
aman diulang. Skrip itu membuat `.env` dengan kata sandi basis data acak,
membuat basis data dan pengguna `silase`, membangun image, memasang skema, lalu
memeriksa bahwa halaman ringkasan menjawab. Bila satu langkah gagal, skrip
berhenti dengan pesan `GAGAL: ...`.

Web terbuka di `http://<IP ir1server>:8088` (port bisa diganti lewat `SILASE_PORT` di `.env`), hanya dari jaringan lokal. Jangan
dibuatkan hostname publik di Cloudflare Tunnel sebelum login (tahap d) selesai.

Worker ingest tidak ikut menyala sampai akun broker diisi:

```bash
# setelah MQTT_USER dan MQTT_PASS diisi di deploy/ir1server/.env
docker compose --profile ingest up -d
docker compose logs -f ingest
```

Untuk mencabut: `docker compose --profile ingest down -v`. Basis data `silase`
di kontainer `mariadb` tidak ikut terhapus.

## Simulator (tanpa perangkat keras)

```bash
php bin/simulasi.php normal                  # satu pembacaan + status online
php bin/simulasi.php riwayat --jam=24        # isi data 24 jam ke belakang
php bin/simulasi.php semua                   # semua skenario uji
php bin/simulasi.php bahaya --device=node-02
```

Skenario: `normal`, `riwayat`, `duplikat`, `terlambat`, `ts0`, `null`, `bahaya`,
`rusak`, `offline`, `semua`. Penjelasan tiap skenario ada di kepala
`bin/simulasi.php`.

Simulator mem-publish ke broker, jadi worker harus sedang berjalan. Tambahkan
`--langsung` untuk melewati broker dan memproses payload langsung ke basis data.

### Mencoba tanpa MariaDB

Isi `DB_SQLITE=/tmp/silase-coba.sqlite` di `.env` untuk memakai berkas SQLite.
Ini hanya untuk mencoba ingest secara lokal, bukan untuk produksi.

## Uji otomatis

```bash
vendor/bin/phpunit
```

Uji memakai SQLite di memori yang dibangun dari `sql/schema.sql`, tanpa broker.
Yang dicakup: duplikat, data terlambat dan tidak berurutan, `ts = 0`, nilai
`null`, payload rusak, status dan last will, kondisi tiap drum di halaman
ringkasan, halaman detail, dan data grafik.

Untuk menjalankan uji yang sama di MariaDB, arahkan `DB_*` ke basis data uji
yang kosong (uji menghapus isi `readings` dan `devices`), lalu:

```bash
SILASE_UJI_MYSQL=1 vendor/bin/phpunit
```

## Struktur

| Lokasi | Isi |
|---|---|
| `sql/schema.sql` | skema semua tabel |
| `src/Ingest/` | validasi payload, penyimpanan, penyalur pesan per topik |
| `src/Drum/` | kondisi drum, kueri, dan deret waktu untuk grafik |
| `src/Web/` | rute Slim, templat, dan format tampilan |
| `src/WsMqttClient.php` | MQTT lewat WebSocket |
| `src/Simulasi/` | penyusun payload seperti firmware |
| `templates/`, `public/` | templat halaman, front controller, dan aset |
| `bin/` | `migrasi.php`, `worker-ingest.php`, `simulasi.php`, `bangun-css.sh` |
| `tests/` | uji PHPUnit |
| `Dockerfile`, `deploy/ir1server/` | image dan stack Docker untuk ir1server |
| `deploy/broker/buat-akun.sh` | pembuat akun broker (dijalankan di host broker) |
| `deploy/silase-ingest.service` | unit systemd (pemasangan tanpa Docker) |

## Usulan untuk firmware

Web tidak mengubah firmware. Tiga hal berikut perlu diputuskan di sisi firmware:

1. **Sambungan lewat WebSocket.** PubSubClient memakai TCP, sedangkan broker lab
   hanya terbuka lewat `wss://mqtt.ir1lab.id:443`. Pilihannya: mengganti klien
   MQTT di firmware dengan yang mendukung WebSocket (misalnya `esp-mqtt` bawaan
   ESP-IDF dengan URI `wss://`), atau membuka port TCP broker (8883 dengan TLS)
   untuk node.
2. **Tabrakan saat `ts = 0`.** `seq` dan `uptime_s` kembali ke nol tiap restart,
   jadi dua restart saat offline bisa menghasilkan kunci duplikat yang sama dan
   baris kedua tertolak. Penghitung boot di payload menutup celah ini.
3. **Perintah tanpa balasan.** `cmd` dikirim QoS 0 tanpa retained, jadi perintah
   hilang bila node sedang offline, dan web tidak tahu perintah diterima atau tidak.
