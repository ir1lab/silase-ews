#!/usr/bin/env bash
# Membangun public/assets/app.css dari kelas Tailwind yang dipakai templat.
# Hanya perlu dijalankan setelah mengubah templat; hasilnya ikut disimpan di git,
# jadi server produksi tidak butuh Node.
set -euo pipefail
cd "$(dirname "$0")/.."
npx --yes tailwindcss@3.4.17 -c tailwind.config.js -i assets-src/app.css -o public/assets/app.css --minify
