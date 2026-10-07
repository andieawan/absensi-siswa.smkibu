#!/usr/bin/env bash
# Memperbarui aplikasi di server. Pakai:  cd /var/www/absensi && sudo bash deploy/update.sh
# Urutan: mode pemeliharaan -> backup -> git pull -> composer -> migrate -> bersihkan cache -> izin -> reload PHP-FPM -> aktif lagi.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then echo "Jalankan dengan sudo: sudo bash deploy/update.sh" >&2; exit 1; fi

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP"
[ -f artisan ] || { echo "artisan tidak ditemukan di $APP" >&2; exit 1; }
[ -f .env ] || { echo ".env tidak ada di $APP — aplikasi belum dipasang?" >&2; exit 1; }

OWNER="$(stat -c %U "$APP")"          # pemilik folder (user yang menjalankan git/composer)
WEB="www-data"
as_owner() { sudo -u "$OWNER" -H "$@"; }
step() { echo; echo "==> $*"; }

DOWN=0
cleanup() {
  if [ "$DOWN" = 1 ]; then
    php artisan up >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

step "Mode pemeliharaan"
php artisan down >/dev/null 2>&1 && DOWN=1 || true

step "Backup database"
if ! sudo -u "$WEB" php artisan absensi:backup; then
  echo "PERINGATAN: backup gagal. Lanjut? (ketik ya untuk lanjut)"
  read -r jawab
  [ "$jawab" = "ya" ] || { echo "Dibatalkan. Aplikasi dikembalikan aktif."; exit 1; }
fi

step "Ambil kode terbaru"
if [ -d .git ]; then
  as_owner git pull --ff-only
else
  echo "Folder ini bukan hasil git clone — lewati git pull (kode diperbarui dari zip)."
fi

step "Paket PHP (composer)"
if command -v composer >/dev/null 2>&1; then
  as_owner composer install --no-dev --optimize-autoloader --no-interaction
else
  echo "composer tidak terpasang — lewati (pastikan folder vendor/ sudah ada)."
fi

step "Migrasi database"
php artisan migrate --force

step "Bersihkan cache"
php artisan optimize:clear

step "Izin folder"
chown -R "$OWNER":"$WEB" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

step "Muat ulang PHP-FPM"
FPM="$(systemctl list-units --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)"
if [ -n "$FPM" ]; then systemctl reload "$FPM" && echo "reload $FPM"; else echo "Servis php-fpm tidak ditemukan — lewati."; fi

step "Aktifkan kembali"
php artisan up && DOWN=0

echo
echo "Selesai. Versi: $(as_owner git rev-parse --short HEAD 2>/dev/null || echo 'zip')"
