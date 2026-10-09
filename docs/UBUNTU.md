# Pasang di Ubuntu Server (dari nol sampai jalan)

Panduan ini untuk **Ubuntu 22.04 / 24.04** dengan **Nginx + PHP-FPM + MariaDB**. Semua perintah dijalankan sebagai
user biasa yang punya `sudo` (bukan langsung `root`). Ganti `absensi.sekolah.sch.id` dengan domain/IP server Anda.

> Ringkasnya: pasang paket → buat database → ambil kode → isi `.env` → `migrate` → Nginx → HTTPS → cron & backup.

## 0. Yang perlu disiapkan
| Hal | Keterangan |
|---|---|
| Server | Ubuntu 22.04/24.04, RAM ≥ 1 GB, akses SSH + `sudo` |
| Alamat | **Domain** (mis. `absensi.sekolah.sch.id` → IP server) *atau* hanya IP lokal (lihat langkah 9) |
| HTTPS | Disarankan. **Wajib** untuk Absen Saya berbasis lokasi (GPS) dan untuk memasang aplikasi ke HP (PWA) |

## 1. Perbarui sistem & firewall
```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Asia/Jakarta
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```
(Perintah `ufw` boleh dilewati bila server sudah dilindungi firewall lain. Pastikan SSH tidak terkunci.)

## 2. Pasang paket
**Ubuntu 24.04** sudah membawa PHP 8.3 (cukup, aplikasi butuh ≥ 8.3):
```bash
sudo apt -y install nginx mariadb-server git unzip curl composer \
  php-fpm php-cli php-mysql php-sqlite3 php-mbstring php-xml php-zip php-curl php-intl
```
**Ubuntu 22.04** hanya punya PHP 8.1 → tambahkan PPA dulu:
```bash
sudo apt -y install software-properties-common && sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt -y install nginx mariadb-server git unzip curl composer \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl php8.3-intl
```
Cek: `php -v` (harus ≥ 8.3) dan `php -m | grep -E "zip|mbstring|pdo_mysql"`.

Naikkan batas unggah PHP (untuk scan surat & impor Excel). Ganti `8.3` sesuai versi Anda:
```bash
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 10M/; s/^post_max_size.*/post_max_size = 12M/' /etc/php/8.3/fpm/php.ini
sudo systemctl restart php8.3-fpm
```

## 3. Buat database
```bash
sudo mariadb
```
Di prompt MariaDB (ganti `PasswordKuat123!`):
```sql
CREATE DATABASE absensi_siswa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'absensi'@'localhost' IDENTIFIED BY 'PasswordKuat123!';
GRANT ALL PRIVILEGES ON absensi_siswa.* TO 'absensi'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Ambil kode aplikasi
```bash
sudo mkdir -p /var/www/absensi && sudo chown $USER:$USER /var/www/absensi
git clone https://github.com/andieawan/absensi-siswa.smkibu.git /var/www/absensi
cd /var/www/absensi
composer install --no-dev --optimize-autoloader
```
*(Tanpa git? Unggah `absensi-laravel13-siap-unggah.zip` ke server, `unzip` ke `/var/www/`, ganti nama foldernya menjadi `absensi`; folder `vendor/` sudah ikut, `composer install` dilewati.)*

## 5. Isi `.env`
```bash
cp .env.example .env
php artisan key:generate
nano .env
```
Yang wajib diubah:
```ini
APP_URL=https://absensi.sekolah.sch.id
DB_DATABASE=absensi_siswa
DB_USERNAME=absensi
DB_PASSWORD=PasswordKuat123!
SESSION_SECURE_COOKIE=true        # ubah ke false BILA belum memakai HTTPS
```
Simpan di nano: `Ctrl+O`, `Enter`, `Ctrl+X`.

## 6. Hak akses folder
```bash
cd /var/www/absensi
sudo chown -R $USER:www-data .
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
```
(User Anda tetap bisa menjalankan `artisan`, sedangkan PHP-FPM (`www-data`) bisa menulis ke `storage/`.)

## 7. Buat tabel & akun Administrator
```bash
php artisan migrate --force
php artisan absensi:admin admin.sekolah      # password ditanya (min. 10 karakter)
```
Opsional — isi **data contoh** untuk mencoba: `php artisan db:seed --class=DemoSeeder`.

## 8. Nginx
```bash
sudo cp /var/www/absensi/deploy/nginx-absensi.conf /etc/nginx/sites-available/absensi
sudo nano /etc/nginx/sites-available/absensi     # ganti server_name, dan versi php8.3-fpm.sock bila beda
sudo ln -s /etc/nginx/sites-available/absensi /etc/nginx/sites-enabled/absensi
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```
Buka `http://absensi.sekolah.sch.id` — halaman login harus muncul.

## 9. HTTPS (pilih salah satu)
**A. Domain publik + Let's Encrypt (paling mudah).** Port 80/443 server terbuka ke internet:
```bash
sudo apt -y install certbot python3-certbot-nginx
sudo certbot --nginx -d absensi.sekolah.sch.id
```
Perpanjangan otomatis sudah diatur certbot (cek: `sudo certbot renew --dry-run`).

**B. Server di jaringan sekolah tanpa port terbuka → Cloudflare Tunnel.** Domain dikelola di Cloudflare; pasang
`cloudflared`, arahkan hostname ke `http://localhost:80`. Lalu di `.env` isi `TRUST_PROXIES=true`.

**C. Hanya jaringan lokal (IP / nama internal), tanpa HTTPS.** Di `.env` ubah `SESSION_SECURE_COOKIE=false` dan
`APP_URL=http://IP-SERVER`. Konsekuensinya: **tombol lokasi (GPS) di Absen Saya dan pemasangan ke layar HP (PWA) tidak
berfungsi** — selebihnya normal. Untuk HTTPS lokal, gunakan A/B atau sertifikat dari CA internal sekolah.

Setelah mengubah `.env`: `php artisan optimize:clear`.

## 10. Cron (backup harian otomatis)
```bash
sudo crontab -u www-data -e
```
Tambahkan satu baris:
```cron
* * * * * cd /var/www/absensi && php artisan schedule:run >> /dev/null 2>&1
```
Backup berjalan tiap pukul 02:00 WIB ke `storage/app/backups` (otomatis menyimpan 8 minggu, bisa diubah di
Admin → Pengaturan). Uji sekarang: `sudo -u www-data php artisan absensi:backup`.

## 11. Cadangan ke luar server (sangat disarankan)
Salin folder backup ke VPS/komputer lain agar aman jika disk rusak. Contoh dengan `rsync` + kunci SSH (di server absensi):
```bash
sudo -u www-data ssh-keygen -t ed25519 -N "" -f /var/www/.ssh/id_ed25519      # sekali saja
sudo -u www-data ssh-copy-id -i /var/www/.ssh/id_ed25519.pub user@IP-VPS
sudo crontab -u www-data -e
```
```cron
30 2 * * * rsync -az --delete /var/www/absensi/storage/app/backups/ user@IP-VPS:/backup/absensi/ >> /dev/null 2>&1
```
Ikut cadangkan juga `.env` dan folder `storage/app/private` (scan surat BK/TU): `rsync -az /var/www/absensi/.env /var/www/absensi/storage/app/private user@IP-VPS:/backup/absensi-files/`.

## 12. Cek akhir
- [ ] Halaman login terbuka lewat HTTPS, gembok hijau.
- [ ] Masuk sebagai Administrator → Admin → **Pengaturan**: isi nama sekolah, tahun ajaran, Kepsek/BK.
- [ ] Admin → **Impor Data**: unduh template, isi Kelas → Mapel → Guru & Staf → Siswa → Pasangan Mapel.
- [ ] Login sebagai guru → buka **Absensi** → simpan satu absensi percobaan.
- [ ] Buka dari HP → "Tambahkan ke layar utama" (PWA).
- [ ] `sudo -u www-data php artisan absensi:backup` berhasil.
- [ ] Hapus `ADMIN_PASSWORD` dari `.env` bila pernah diisi.

## 13. Memperbarui ke versi baru
**Cara singkat (disarankan):**
```bash
cd /var/www/absensi
sudo bash deploy/update.sh
```
Skrip ini membuat backup, `git pull`, `composer install`, `migrate`, membersihkan cache, memperbaiki izin, dan memuat ulang PHP-FPM; aplikasi otomatis kembali aktif walau ada langkah yang gagal.

Cara manual (setara):
```bash
cd /var/www/absensi
php artisan down --secret=rahasia           # opsional: mode pemeliharaan
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan up
```
Backup otomatis dibuat sebelum migrasi lewat tombol Admin → Pengaturan → *Perbarui Database*; bila memakai terminal, jalankan
`php artisan absensi:backup` **sebelum** `migrate`.

## 13b. Memulihkan (restore) database dari backup

Backup harian tersimpan di `storage/app/backups/absensi_*.sql` (juga bisa diunduh dari Admin → Pengaturan → Backup).

```bash
cd /var/www/absensi
sudo bash deploy/restore.sh                      # pilih dari daftar backup
sudo bash deploy/restore.sh /path/absensi_2026-10-08T01-00-00.sql   # atau berkas tertentu (mis. hasil unduhan)
```

Skrip: membuat **backup pengaman** kondisi sekarang → mode pemeliharaan → mengimpor → `migrate` → bersihkan cache → aktif lagi.
Semua pengguna perlu login ulang. Bila salah pilih, jalankan skrip lagi dan pilih backup pengaman yang disebut di akhir proses.
Untuk berkas dari komputer: unggah dengan `scp absensi_xxx.sql user@server:/tmp/` lalu berikan path-nya.

## 14. Jika ada masalah
| Gejala | Penyebab & solusi |
|---|---|
| **500 Server Error** | Lihat `tail -50 /var/www/absensi/storage/logs/laravel.log`. Umumnya `APP_KEY` kosong (`php artisan key:generate`), `.env` salah, atau izin folder (langkah 6) |
| **502 Bad Gateway** | PHP-FPM mati/soket salah: `sudo systemctl status php8.3-fpm`; samakan versi di `fastcgi_pass` |
| **404 untuk semua halaman selain `/`** | `root` Nginx harus menunjuk ke folder **`public`**, dan blok `location /` memakai `try_files … /index.php?$query_string` |
| **Unknown database / Access denied** | `DB_*` di `.env` tidak cocok dengan langkah 3. Uji: `mariadb -u absensi -p absensi_siswa` |
| **Permission denied … storage/logs** | Ulangi langkah 6 |
| **Terkunci "Terlalu banyak percobaan login"** | Tunggu 15 menit atau `php artisan cache:clear` |
| **Logout sendiri / cookie tidak tersimpan** | Situs belum HTTPS tetapi `SESSION_SECURE_COOKIE=true` → ubah ke `false` atau pasang HTTPS |
| **Jam/tanggal beda** | `sudo timedatectl set-timezone Asia/Jakarta` dan `ABSENSI_TIMEZONE=Asia/Jakarta` di `.env` |
| **Unggah Excel/scan gagal (413)** | Naikkan `client_max_body_size` (Nginx) dan `upload_max_filesize`/`post_max_size` (PHP, langkah 2) |
| **Perubahan `.env` tidak terbaca** | `php artisan optimize:clear` |

Daftar perintah artisan lengkap: lihat bagian **Perintah berguna** di `README.md`.
