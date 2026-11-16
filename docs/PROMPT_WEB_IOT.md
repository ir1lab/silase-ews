# Prompt pengembangan web IoT SILASE-EWS

Prompt ini dipakai untuk membangun aplikasi web di folder `web/` pada repo ini.
Kontrak datanya diambil dari firmware v1.0 (`src/`, `include/`).

```text
Bangun aplikasi web IoT "SILASE-EWS Web" di folder web/ pada repo ini.
Firmware node ada di src/ dan include/; baca README.md dan include/config.h
dulu, karena web harus mengikuti format data firmware, bukan sebaliknya.

## Tujuan
Dasbor untuk operator koperasi peternakan dan peneliti: memantau fermentasi
silase per drum, menerima peringatan dini, dan mengekspor data untuk analisis.
Pengguna utama adalah operator non-teknis yang membuka dari ponsel di kandang.

## Stack
- Backend: PHP 8.2 tanpa framework berat (atau Slim), MySQL 8, PDO.
- Ingest MQTT: satu worker PHP CLI yang berjalan terus (php-mqtt/client),
  dijalankan lewat systemd atau supervisor.
- Frontend: HTML + Tailwind + Chart.js, tanpa build step yang rumit.
- Bahasa antarmuka: Indonesia. Zona waktu tampilan: Asia/Jakarta.
- Konfigurasi lewat .env (host broker, kredensial, DB). Sertakan .env.example.

## Kontrak data dari firmware (jangan diubah)
Topik: silase-ews/<DEVICE_ID>/data | /status | /cmd

1. data (JSON, tiap 5 menit):
{"device_id":"node-01","fw":"1.0.0","seq":12,"ts":1794150000,"uptime_s":3600,
 "temp":{"bawah":29.81,"tengah":31.06,"atas":30.44,"ambien":28.5},
 "headspace":{"co2_ppm":8450,"temp":29.9,"rh":96.2},
 "ews":{"level":"NORMAL","dT":2.56,"hotspot":"tengah","co2_leak":false,
        "co2_max":9120,"stabilizing":true,"cycle_h":1.0,"reason":"..."},
 "sensor_flags":0}
   - level: NORMAL | WASPADA | BAHAYA.
   - Nilai sensor yang tidak valid dikirim null. Simpan sebagai NULL,
     tampilkan sebagai celah pada grafik, jangan diisi nol.
   - ts = epoch detik UTC; bernilai 0 bila jam node belum sinkron.
     Bila ts = 0, pakai waktu terima server dan tandai barisnya.
   - Data dari buffer offline bisa datang terlambat, berurutan, dan terkirim
     ulang. Tolak duplikat dengan kunci unik (device_id, ts), dan untuk
     ts = 0 dengan (device_id, seq, uptime_s).
   - sensor_flags (bitmask sensor gagal): bit0 bawah, bit1 tengah, bit2 atas,
     bit3 ambien, bit4 SCD41.
2. status (retained): JSON berisi online, level, reason. Last will dari
   broker: {"online":false}.
3. cmd: payload teks polos, salah satu dari start_cycle, sample_now, mute.

## Model data
- devices: device_id, nama drum, lokasi, catatan, last_seen, online.
- readings: semua kolom payload data (diratakan), received_at, late (bool).
- cycles: device_id, mulai, selesai, bahan pakan, catatan, hasil akhir
  (baik/rusak, pH akhir, catatan organoleptik) yang diisi manual.
- alerts: device_id, level, reason, mulai, selesai, diakui_oleh, diakui_pada.
  Buat baris baru hanya saat level berubah, bukan tiap pembacaan.
- alert_recipients: device_id, nama, nomor (format 628xxx), aktif.
- notifications: alert_id, nomor, provider, status, percobaan, galat
  terakhir, waktu kirim.
- users: nama, email, hash kata sandi, peran (admin, operator, peneliti).
- commands_log: siapa mengirim perintah apa, kapan.

## Halaman
1. Ringkasan: kartu per drum dengan warna status (hijau/kuning/merah/abu
   untuk offline), dT sekarang, CO2, umur siklus, waktu data terakhir.
   Drum yang datanya lebih tua dari 15 menit ditandai "tidak ada data".
2. Detail drum: grafik suhu 4 kanal, grafik dT dengan garis ambang 2 dan
   5 derajat C, grafik CO2 dan RH; pilihan rentang 24 jam, 7 hari, satu
   siklus; riwayat peringatan; indikator sensor gagal dari sensor_flags.
3. Peringatan: daftar aktif dan riwayat, tombol "akui".
4. Siklus: mulai siklus baru (mengirim start_cycle dan membuat baris
   cycles), tutup siklus dengan isian hasil akhir.
5. Perintah: sample_now dan mute, hanya admin dan operator, dengan
   konfirmasi; semua tercatat di commands_log.
6. Ekspor: CSV per drum per rentang waktu atau per siklus, untuk peneliti.
7. Login dan kelola pengguna (admin).
8. Pengaturan WhatsApp (admin): status gateway, kode QR, penerima per
   drum, tombol "kirim pesan uji".

## Notifikasi (WhatsApp)
Saat alerts baru dibuat dengan level WASPADA atau BAHAYA, kirim pesan
WhatsApp ke nomor aktif di alert_recipients untuk drum itu. Kirim juga
pesan "pulih" saat level kembali NORMAL.

- Buat antarmuka WhatsAppNotifier dengan satu metode
  send(nomor, jenis, data), dan dua implementasi yang dipilih lewat .env
  (WA_PROVIDER=gateway | cloud_api):
  a. gateway (dipakai pada tahap awal): gateway WhatsApp tidak resmi yang
     di-hosting sendiri (WAHA Core lewat Docker; sediakan
     docker-compose.yml). Web hanya memanggil HTTP API gateway di jaringan
     lokal; port gateway tidak boleh terbuka ke internet dan API-nya
     dilindungi kunci di .env. Isi pesan berupa teks biasa.
  b. cloud_api (dipakai setelah layanan stabil): WhatsApp Cloud API resmi
     (Meta). Pesan dikirim sebagai template yang sudah disetujui; nama
     template dan bahasa diambil dari .env. Pada tahap awal cukup dibuat
     sebagai kelas dengan antarmuka yang sama dan uji unit, tanpa
     diaktifkan; perpindahan nanti hanya lewat .env.
- Susun isi pesan gateway dengan urutan parameter yang sama seperti
  template Cloud API (nama drum, level, dT, alasan, waktu).
- Isi pesan (maks. 5 baris, Bahasa Indonesia), contoh:
  "PERINGATAN SILASE - BAHAYA
   Drum: Drum 3 (Kandang A)
   Suhu naik 5,4 C di atas ambien (lapis tengah)
   16 Nov 2026 14.05 WIB
   Periksa tutup dan segel drum."
- Jangan kirim ulang selama level tidak berubah. Untuk BAHAYA yang belum
  diakui, kirim pengingat tiap 6 jam, maksimum 3 kali.
- Batasi laju kirim: jeda acak 3-8 detik antarpesan, maksimum 20 pesan
  per jam per nomor pengirim. Hanya kirim ke nomor di alert_recipients.
- Pengiriman lewat antrean (tabel notifications) dengan coba ulang 3 kali;
  kegagalan kirim tidak boleh menghentikan worker ingest.
- Pantau kesehatan gateway tiap 5 menit. Bila sesi terputus, tampilkan
  banner merah di dasbor ("WhatsApp terputus, pindai ulang QR") dan kirim
  email ke admin.
- Pesan yang gagal karena gateway terputus tetap di antrean dan dikirim
  setelah tersambung, kecuali sudah lebih dari 2 jam atau statusnya sudah
  pulih; dalam kasus itu tandai "kedaluwarsa", jangan dikirim.
- Peringatan tetap tercatat dan tampil di dasbor walaupun WhatsApp gagal.
- Token dan kredensial hanya di .env; nomor penerima tidak ditampilkan
  penuh kepada peran peneliti.

## Keamanan
- Kata sandi di-hash (password_hash), sesi dengan cookie HttpOnly, CSRF
  token pada semua form, prepared statement untuk semua kueri.
- Worker MQTT memakai akun broker terpisah dengan ACL: baca data/status,
  tulis cmd. Jangan menaruh kredensial broker di kode frontend.
- Validasi payload di worker; payload yang rusak dicatat lalu dibuang,
  worker tidak boleh berhenti.

## Yang tidak perlu dibuat sekarang
Aplikasi mobile native, multi-koperasi (multi-tenant), prediksi machine
learning, dan pengaturan ambang dari web (ambang masih di firmware).

## Cara kerja yang saya minta
1. Mulai dengan rencana singkat dan skema SQL; tunggu persetujuan saya.
2. Kerjakan bertahap: (a) skema + worker ingest + skrip simulasi yang
   mem-publish payload contoh ke broker, (b) ringkasan dan detail drum,
   (c) peringatan dan WhatsApp, (d) siklus, perintah, ekspor, pengguna.
3. Tiap tahap harus bisa dijalankan dan diuji dengan skrip simulasi, tanpa
   perangkat keras. Sertakan uji untuk duplikat, data terlambat, ts = 0,
   dan nilai null.
4. Tulis web/README.md: cara pasang, menjalankan worker, dan deploy.
5. Jangan mengubah berkas firmware. Bila format data terasa kurang,
   sampaikan sebagai usulan, jangan diubah sendiri.
```

## Catatan pemakaian

- Notifikasi WhatsApp tahap awal memakai gateway tidak resmi: pakai nomor
  khusus, dan jangan jadikan WhatsApp satu-satunya jalur peringatan. Buzzer
  dan LED pada node tetap jalur utama.
- Pindah ke WhatsApp Cloud API resmi setelah layanan stabil, misalnya dua
  sampai tiga siklus fermentasi dengan tingkat pesan terkirim di atas 95%.
