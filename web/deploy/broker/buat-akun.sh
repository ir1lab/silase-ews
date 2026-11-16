#!/usr/bin/env bash
# Membuat akun broker SILASE-EWS di Mosquitto (plugin Dynamic Security).
# Dijalankan DI HOST BROKER (MQTT-Server) sebagai root:
#   bash buat-akun.sh
#
#   silase-node : firmware node  -> tulis data & status, baca cmd
#   silase-web  : worker dan web -> baca data & status, tulis cmd
#
# Aman diulang: akun yang sudah ada hanya diganti kata sandinya.
# Kata sandi dibuat acak dan ditampilkan sekali di akhir.
set -uo pipefail

ADMIN_USER="${ADMIN_USER:-admin}"
AKAR="silase-ews"

command -v mosquitto_ctrl >/dev/null || { echo "GAGAL: mosquitto_ctrl tidak ditemukan. Jalankan skrip ini di host broker." >&2; exit 1; }

read -rsp "Kata sandi admin dynsec ($ADMIN_USER): " ADMIN_PASS </dev/tty; echo
[ -n "$ADMIN_PASS" ] || { echo "GAGAL: kata sandi admin kosong." >&2; exit 1; }

dyn() { mosquitto_ctrl -u "$ADMIN_USER" -P "$ADMIN_PASS" dynsec "$@"; }

echo "[1/4] Memeriksa login admin"
dyn listClients >/dev/null 2>/tmp/silase-dynsec.err || {
  echo "GAGAL: tidak bisa masuk sebagai $ADMIN_USER:" >&2; cat /tmp/silase-dynsec.err >&2; exit 1; }

echo "[2/4] Membuat peran dan hak akses"
# Galat "sudah ada" saat diulang diabaikan; hasilnya diperiksa di langkah 4.
peran() {            # peran <nama> <jenis-acl> <topik> ...
  local nama="$1"; shift
  dyn createRole "$nama" >/dev/null 2>&1
  while [ $# -gt 0 ]; do dyn addRoleACL "$nama" "$1" "$2" allow >/dev/null 2>&1; shift 2; done
}
peran silase-node publishClientSend "$AKAR/+/data" publishClientSend "$AKAR/+/status" subscribePattern "$AKAR/+/cmd"
peran silase-web  subscribePattern  "$AKAR/+/data" subscribePattern  "$AKAR/+/status" publishClientSend "$AKAR/+/cmd"

echo "[3/4] Membuat akun"
# mosquitto_ctrl mengembalikan kode 0 walaupun gagal, jadi keberadaan akun
# selalu diperiksa dari daftar akun, bukan dari kode keluarnya.
ada() { dyn listClients 2>/dev/null | grep -qxF "$1"; }
akun() {             # akun <nama> <kata-sandi>
  local keluaran
  if ada "$1"; then
    keluaran="$(dyn setClientPassword "$1" "$2" 2>&1)"
  else
    keluaran="$(dyn createClient "$1" -p "$2" 2>&1)"
  fi
  if ! ada "$1" || printf '%s' "$keluaran" | grep -qi 'error'; then
    printf '%s\n' "$keluaran" | grep -vi -e 'without encryption' -e 'visible on the network' -e '^$' >&2
    return 1
  fi
  dyn addClientRole "$1" "$1" >/dev/null 2>&1
  return 0
}
PASS_NODE="$(openssl rand -hex 16)"
PASS_WEB="$(openssl rand -hex 16)"
akun silase-node "$PASS_NODE" || { echo "GAGAL: akun silase-node tidak bisa dibuat." >&2; exit 1; }
akun silase-web  "$PASS_WEB"  || { echo "GAGAL: akun silase-web tidak bisa dibuat." >&2; exit 1; }

echo "[4/4] Memeriksa hasil"
ok=1
for a in silase-node silase-web; do
  ada "$a" && echo "  $a: akun ada" || { echo "  GAGAL: akun $a tidak ada"; ok=0; }
  n="$(dyn getRole "$a" 2>/dev/null | grep -c "$AKAR/")"
  [ "$n" -ge 3 ] && echo "  $a: peran punya $n hak akses" || { echo "  GAGAL: peran $a hanya punya $n hak akses (harus 3)"; ok=0; }
done
# Uji login sungguhan. Topik uji tidak punya pelanggan, jadi tidak ada efek samping.
if command -v mosquitto_pub >/dev/null; then
  for pasangan in "silase-node:$PASS_NODE" "silase-web:$PASS_WEB"; do
    if mosquitto_pub -u "${pasangan%%:*}" -P "${pasangan#*:}" -t "$AKAR/uji-akun/cmd" -m sample_now 2>/tmp/silase-dynsec.err; then
      echo "  ${pasangan%%:*}: login ke broker berhasil"
    else
      echo "  GAGAL: ${pasangan%%:*} tidak bisa login: $(cat /tmp/silase-dynsec.err)"; ok=0
    fi
  done
fi
rm -f /tmp/silase-dynsec.err
[ "$ok" = 1 ] || { echo "ADA YANG GAGAL. Kata sandi tidak ditampilkan; perbaiki lalu ulangi." >&2; exit 1; }

cat <<SELESAI

BERHASIL. Salin empat baris ini ke deploy/ir1server/.env di ir1server
(ganti baris MQTT_USER dan MQTT_PASS yang lama):

MQTT_USER=silase-web
MQTT_PASS=$PASS_WEB
SIM_MQTT_USER=silase-node
SIM_MQTT_PASS=$PASS_NODE

Kata sandi silase-node juga diisikan ke firmware (MQTT_USER / MQTT_PASSWORD).
Kata sandi ini tidak disimpan di mana pun; bila hilang, jalankan skrip ini lagi.
SELESAI
