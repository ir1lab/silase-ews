#!/usr/bin/env bash
# Memasang atau memperbarui SILASE-EWS Web di ir1server. Dijalankan di HOST:
#   cd /srv/projects/nextjs/silase/web/deploy/ir1server && bash pasang.sh
# Aman diulang: tiap langkah memeriksa hasilnya dan berhenti bila gagal.
set -euo pipefail
cd "$(dirname "$0")"

gagal() { echo "GAGAL: $*" >&2; exit 1; }

echo "[1/5] Memeriksa prasyarat"
docker network inspect web >/dev/null 2>&1 || gagal "network docker 'web' tidak ada"
[ "$(docker inspect -f '{{.State.Running}}' mariadb 2>/dev/null)" = "true" ] || gagal "kontainer 'mariadb' tidak berjalan"

if [ ! -f .env ]; then
  ( umask 077; sed "s|^DB_PASS=.*|DB_PASS=$(openssl rand -hex 20)|" env.example > .env )
  echo "      .env dibuat dengan kata sandi basis data acak"
fi
# Versi baru bisa membawa pengaturan baru: tambahkan yang belum ada di .env,
# tanpa mengubah nilai yang sudah diisi.
while IFS= read -r baris; do
  kunci="${baris%%=*}"
  grep -q "^${kunci}=" .env || { printf '%s\n' "$baris" >> .env; echo "      .env: menambahkan $kunci"; }
done < <(grep -E '^[A-Z_]+=' env.example)
if [ -z "$(sed -n 's/^WA_GATEWAY_KEY=//p' .env)" ]; then
  sed -i "s|^WA_GATEWAY_KEY=.*|WA_GATEWAY_KEY=$(openssl rand -hex 24)|" .env
  echo "      .env: kunci API gateway WhatsApp dibuat acak"
fi
DB_NAME="$(sed -n 's/^DB_NAME=//p' .env)"
DB_USER="$(sed -n 's/^DB_USER=//p' .env)"
DB_PASS="$(sed -n 's/^DB_PASS=//p' .env)"
[ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && [ -n "$DB_PASS" ] || gagal "DB_NAME, DB_USER, atau DB_PASS di .env kosong"

# Port host tidak boleh bentrok dengan layanan lain (kecuali memang sudah dipakai silase-web).
PORT="$(sed -n 's/^SILASE_PORT=//p' .env)"; PORT="${PORT:-8088}"
if [ -z "$(docker ps -q -f name='^silase-web$')" ] && ss -ltn 2>/dev/null | awk '{print $4}' | grep -qE "[:.]$PORT\$"; then
  gagal "port $PORT di host sudah dipakai layanan lain; ganti SILASE_PORT di $(pwd)/.env lalu ulangi"
fi
echo "      port host $PORT kosong"

echo "[2/5] Menyiapkan basis data '$DB_NAME' di kontainer mariadb"
# Kata sandi root diambil dari lingkungan kontainer mariadb, tidak ditampilkan.
docker exec -i mariadb sh -c 'exec mariadb -uroot -p"${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"' <<SQL || gagal "tidak bisa membuat basis data (cek kata sandi root mariadb)"
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'%' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
SQL

echo "[3/5] Membangun image dan menyalakan web serta worker notifikasi"
docker compose up -d --build web

echo "[4/5] Memasang skema"
docker compose exec -T web php bin/migrasi.php || gagal "migrasi skema gagal"

# Worker notifikasi butuh skema yang sudah terpasang, jadi dinyalakan setelah migrasi.
docker compose up -d --force-recreate notifikasi

echo "[5/5] Memeriksa halaman"
kode=000
for _ in $(seq 1 15); do
  kode="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$PORT/ || true)"
  [ "$kode" = "200" ] && break
  sleep 2
done
[ "$kode" = "200" ] || gagal "halaman ringkasan menjawab HTTP $kode (lihat: docker compose logs web)"

# Worker ingest ikut diperbarui bila sudah pernah dinyalakan.
if [ -n "$(docker ps -q -f name='^silase-ingest$')" ]; then
  docker compose --profile ingest up -d --force-recreate ingest
fi

echo
echo "BERHASIL. Web: http://$(hostname -I | awk '{print $1}'):$PORT  (jaringan lokal saja)"
docker compose --profile ingest --profile wa ps --format 'table {{.Service}}\t{{.Status}}'
if [ -z "$(sed -n 's/^MQTT_PASS=//p' .env)" ]; then
  echo "Worker MQTT belum dinyalakan: isi MQTT_USER dan MQTT_PASS di $(pwd)/.env, lalu"
  echo "  docker compose --profile ingest up -d"
fi
if [ -z "$(sed -n 's/^WA_GATEWAY_URL=//p' .env)" ]; then
  echo "WhatsApp belum diaktifkan: peringatan hanya tampil di dasbor. Cara mengaktifkan ada di README."
fi
