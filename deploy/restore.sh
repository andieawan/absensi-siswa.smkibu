#!/usr/bin/env bash
# Memulihkan (restore) database dari berkas backup.
# Pakai:  cd /var/www/absensi && sudo bash deploy/restore.sh            (pilih dari daftar backup)
#         sudo bash deploy/restore.sh /path/ke/absensi_2026-10-08T01-00-00.sql
# Urutan: pilih berkas -> backup pengaman kondisi sekarang -> mode pemeliharaan -> impor -> migrate -> bersihkan cache -> aktif lagi.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then echo "Jalankan dengan sudo: sudo bash deploy/restore.sh" >&2; exit 1; fi

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP"
[ -f artisan ] || { echo "artisan tidak ditemukan di $APP" >&2; exit 1; }
[ -f .env ] || { echo ".env tidak ada di $APP" >&2; exit 1; }

WEB="${WEB_USER:-www-data}"
step() { echo; echo "==> $*"; }
# Nilai konfigurasi: variabel lingkungan lebih dulu, lalu .env
envval() {
  local k="$1" v="${!1:-}"
  if [ -z "$v" ]; then v="$(grep -E "^${k}=" .env | tail -n1 | cut -d= -f2- || true)"; fi
  v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
  printf '%s' "$v"
}

DRIVER="$(envval DB_CONNECTION)"
BACKUP_DIR="$(envval ABSENSI_BACKUP_DIR)"; BACKUP_DIR="${BACKUP_DIR:-$APP/storage/app/backups}"

# 1. Pilih berkas
FILE="${1:-}"
if [ -z "$FILE" ]; then
  mapfile -t LIST < <(ls -1t "$BACKUP_DIR"/absensi_*.sql "$BACKUP_DIR"/absensi_*.sqlite3 2>/dev/null | head -20)
  [ "${#LIST[@]}" -gt 0 ] || { echo "Tidak ada backup di $BACKUP_DIR. Berikan path berkas: sudo bash deploy/restore.sh /path/backup.sql" >&2; exit 1; }
  echo "Backup tersedia (terbaru di atas):"
  i=1; for f in "${LIST[@]}"; do printf '  %2d) %s  (%s)\n' "$i" "$(basename "$f")" "$(date -r "$f" '+%d/%m/%Y %H:%M')"; i=$((i+1)); done
  read -r -p "Nomor backup yang dipulihkan: " N
  [[ "$N" =~ ^[0-9]+$ ]] && [ "$N" -ge 1 ] && [ "$N" -le "${#LIST[@]}" ] || { echo "Pilihan tidak valid." >&2; exit 1; }
  FILE="${LIST[$((N-1))]}"
fi
[ -f "$FILE" ] || { echo "Berkas tidak ditemukan: $FILE" >&2; exit 1; }
case "$FILE" in *.sql|*.sqlite3) ;; *) echo "Berkas harus .sql atau .sqlite3" >&2; exit 1;; esac

echo
echo "Akan memulihkan: $FILE"
echo "SEMUA data saat ini akan DIGANTI dengan isi backup tersebut."
read -r -p "Ketik PULIHKAN untuk melanjutkan: " OK
[ "$OK" = "PULIHKAN" ] || { echo "Dibatalkan. Tidak ada yang diubah."; exit 1; }

DOWN=0
cleanup() { if [ "$DOWN" = 1 ]; then php artisan up >/dev/null 2>&1 || true; fi; }
trap cleanup EXIT

# 2. Backup pengaman (agar restore bisa dibatalkan)
step "Backup pengaman kondisi sekarang"
SAFE=""
if sudo -u "$WEB" php artisan absensi:backup; then
  SAFE="$(ls -1t "$BACKUP_DIR"/absensi_* 2>/dev/null | head -1 || true)"
  echo "Tersimpan: $SAFE"
else
  echo "PERINGATAN: backup pengaman gagal."
  read -r -p "Tetap lanjut memulihkan? (ketik ya): " J
  [ "$J" = "ya" ] || { echo "Dibatalkan."; exit 1; }
fi

step "Mode pemeliharaan"
php artisan down >/dev/null 2>&1 && DOWN=1 || true

# 3. Impor
step "Memulihkan database"
if [ "$DRIVER" = "sqlite" ]; then
  DBFILE="$(envval DB_DATABASE)"; DBFILE="${DBFILE:-$APP/database/database.sqlite}"
  case "$FILE" in
    *.sqlite3) cp -f "$FILE" "$DBFILE" ;;
    *) rm -f "$DBFILE"; sqlite3 "$DBFILE" < "$FILE" ;;
  esac
  chown "$WEB":"$WEB" "$DBFILE" 2>/dev/null || true
else
  case "$FILE" in *.sqlite3) echo "Berkas .sqlite3 hanya untuk SQLite; server ini memakai MySQL/MariaDB." >&2; exit 1;; esac
  DB_HOST="$(envval DB_HOST)"; DB_PORT="$(envval DB_PORT)"; DB_NAME="$(envval DB_DATABASE)"
  DB_USER="$(envval DB_USERNAME)"; DB_PASS="$(envval DB_PASSWORD)"
  command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1 || { echo "Klien mysql/mariadb belum terpasang (sudo apt install mariadb-client)." >&2; exit 1; }
  CLI="$(command -v mariadb || command -v mysql)"
  MYSQL_PWD="$DB_PASS" "$CLI" -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USER" --default-character-set=utf8mb4 "$DB_NAME" < "$FILE"
fi
echo "Impor selesai."

# 4. Selaraskan skema bila backup dari versi lama
step "Migrasi database (menyusul versi terbaru)"
php artisan migrate --force
php artisan optimize:clear >/dev/null
rm -f storage/framework/sessions/* 2>/dev/null || true   # semua pengguna login ulang
chown -R "$WEB":"$WEB" storage 2>/dev/null || true

step "Mengaktifkan aplikasi"
php artisan up >/dev/null 2>&1 && DOWN=0 || true
echo
echo "Selesai. Database dipulihkan dari: $(basename "$FILE")"
[ -n "$SAFE" ] && echo "Bila ternyata salah pilih, kembalikan dengan: sudo bash deploy/restore.sh $SAFE"
