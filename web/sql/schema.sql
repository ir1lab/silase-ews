-- SILASE-EWS Web - skema basis data (MariaDB 11 / MySQL 8)
-- Semua kolom DATETIME disimpan dalam UTC; tampilan diubah ke Asia/Jakarta di aplikasi.
-- Berkas ini juga dipakai uji otomatis (dikonversi ke SQLite oleh Silase\Db),
-- jadi: satu definisi kolom/kunci per baris, dan baris KEY tidak boleh menjadi
-- baris terakhir sebuah tabel.

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  peran ENUM('admin','operator','peneliti') NOT NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  dibuat_pada DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS devices (
  device_id VARCHAR(32) PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  lokasi VARCHAR(100) NULL,
  catatan TEXT NULL,
  online TINYINT(1) NOT NULL DEFAULT 0,
  status_level ENUM('NORMAL','WASPADA','BAHAYA') NULL,
  status_reason VARCHAR(120) NULL,
  fw VARCHAR(16) NULL,
  last_seen DATETIME NULL,
  last_ts INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS readings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(32) NOT NULL,
  dedup_key VARCHAR(40) NOT NULL,
  ts INT UNSIGNED NOT NULL,
  ts_valid TINYINT(1) NOT NULL,
  measured_at DATETIME NOT NULL,
  received_at DATETIME NOT NULL,
  late TINYINT(1) NOT NULL DEFAULT 0,
  seq INT UNSIGNED NOT NULL,
  uptime_s INT UNSIGNED NOT NULL,
  fw VARCHAR(16) NULL,
  t_bawah DECIMAL(5,2) NULL,
  t_tengah DECIMAL(5,2) NULL,
  t_atas DECIMAL(5,2) NULL,
  t_ambien DECIMAL(5,2) NULL,
  co2_ppm DECIMAL(8,2) NULL,
  hs_temp DECIMAL(5,2) NULL,
  hs_rh DECIMAL(5,2) NULL,
  level ENUM('NORMAL','WASPADA','BAHAYA') NOT NULL,
  dt DECIMAL(5,2) NULL,
  hotspot ENUM('bawah','tengah','atas') NULL,
  co2_leak TINYINT(1) NOT NULL,
  co2_max DECIMAL(8,2) NULL,
  stabilizing TINYINT(1) NOT NULL,
  cycle_h DECIMAL(8,2) NULL,
  reason VARCHAR(120) NULL,
  sensor_flags SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_dedup (device_id, dedup_key),
  KEY ix_waktu (device_id, measured_at),
  FOREIGN KEY (device_id) REFERENCES devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cycles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(32) NOT NULL,
  mulai DATETIME NOT NULL,
  selesai DATETIME NULL,
  bahan_pakan VARCHAR(150) NULL,
  catatan TEXT NULL,
  hasil ENUM('baik','rusak') NULL,
  ph_akhir DECIMAL(4,2) NULL,
  catatan_organoleptik TEXT NULL,
  dibuat_oleh INT UNSIGNED NULL,
  KEY ix_cycle (device_id, mulai),
  FOREIGN KEY (device_id) REFERENCES devices(device_id),
  FOREIGN KEY (dibuat_oleh) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(32) NOT NULL,
  level ENUM('WASPADA','BAHAYA') NOT NULL,
  reason VARCHAR(120) NULL,
  dt DECIMAL(5,2) NULL,
  hotspot ENUM('bawah','tengah','atas') NULL,
  mulai DATETIME NOT NULL,
  selesai DATETIME NULL,
  diakui_oleh INT UNSIGNED NULL,
  diakui_pada DATETIME NULL,
  pengingat_terkirim TINYINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_aktif (device_id, selesai),
  FOREIGN KEY (device_id) REFERENCES devices(device_id),
  FOREIGN KEY (diakui_oleh) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alert_recipients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(32) NOT NULL,
  nama VARCHAR(100) NOT NULL,
  nomor VARCHAR(20) NOT NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_penerima (device_id, nomor),
  FOREIGN KEY (device_id) REFERENCES devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alert_id INT UNSIGNED NULL,
  nomor VARCHAR(20) NOT NULL,
  jenis ENUM('peringatan','pengingat','pulih','uji') NOT NULL,
  provider ENUM('gateway','cloud_api') NOT NULL,
  isi TEXT NOT NULL,
  status ENUM('antre','terkirim','gagal','kedaluwarsa') NOT NULL DEFAULT 'antre',
  percobaan TINYINT UNSIGNED NOT NULL DEFAULT 0,
  galat_terakhir VARCHAR(255) NULL,
  dibuat_pada DATETIME NOT NULL,
  dikirim_pada DATETIME NULL,
  KEY ix_antre (status, dibuat_pada),
  FOREIGN KEY (alert_id) REFERENCES alerts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commands_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  device_id VARCHAR(32) NOT NULL,
  perintah ENUM('start_cycle','sample_now','mute') NOT NULL,
  terkirim TINYINT(1) NOT NULL,
  dikirim_pada DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (device_id) REFERENCES devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pengaturan (
  kunci VARCHAR(64) PRIMARY KEY,
  nilai TEXT NULL,
  diubah_pada DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
