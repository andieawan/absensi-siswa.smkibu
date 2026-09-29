# Absensi Siswa — versi PHP penuh

Seluruh aplikasi (tampilan **dan** server) ditulis ulang dalam **PHP murni**: halaman dirender di server,
JavaScript hanya sedikit (tombol "Set Semua Hadir", hitung H/I/S/A, konfirmasi). **Tanpa React, tanpa Node.js,
tanpa build.** Cukup unggah ke hosting biasa (cPanel/shared hosting: Apache + PHP + MySQL).

## Fitur
| Halaman | Isi |
|---|---|
| **Login** | Cookie sesi (HttpOnly, SameSite), batas 10 gagal / 15 menit per IP+username, **Ganti Akun** (verifikasi ulang password), ganti password mandiri |
| **Dashboard** | 3 varian: Wali Kelas · Per Mapel · Sekolah (Kepsek). KPI H/I/S/A, ringkasan & saran otomatis (ambang batas), tren per tanggal, deteksi pola absen berkala (hari sama, jarak 10–18 hari), daftar "Perlu Perhatian" + sinyal absen+nilai turun |
| **Absensi** | Wali (harian) / Per Mapel, tombol H·I·S·A + catatan, UPSERT, peringatan 85%, riwayat sesi (hapus ≤ 7 hari, terkunci setelahnya), **tautan delegasi Ketua Kelas** (maks. 24 jam), unduh Excel |
| **Nilai** | Input/edit kegiatan (angka 0–100 atau huruf A–E), daftar kegiatan (hapus ≤ 7 hari), rekap + rata-rata, unduh Excel |
| **Riwayat Siswa (360)** | Profil, kehadiran, pola, uji pengesahan 85% (dispensasi Admin/Kepsek), log absen, dossier nilai, **akses Portal Wali Murid** (buat/cabut), surat peringatan & panggilan |
| **Integrasi BK** | Input absensi manual BK, rekap ketidakhadiran per kelas, surat panggilan, unduh Excel |
| **Admin Panel** | Akun guru (tambah/ubah/reset/nonaktifkan) · Data siswa (tambah/ubah/**impor xlsx/csv**) · Kelas & Mapel · Pasangan Guru–Mapel–Kelas · Upload Hardcopy (template → pratinjau → simpan) · Log Aktivitas · Pengaturan & Backup |
| **Publik** | `?p=delegasi&token=kk_…` (Ketua Kelas, tanpa login) dan `?p=wali&token=wm_…` (Wali Murid, baca-saja, hanya kehadiran) |

Aturan bisnis sama dengan versi sebelumnya: batas 7 hari (non-admin), syarat kehadiran 85%, otorisasi guru
(wali kelas untuk absen harian; pasangan / kelas+mapel untuk absen mapel), token delegasi 24 jam, audit log 500 baris.
Format hash password `sha256:<salt>:<hash>` **tidak berubah**, jadi akun dari versi lama tetap bisa login.

## Kebutuhan
- PHP **8.1+**: `pdo_mysql` (atau `pdo_sqlite`), `mbstring`, `zip` (untuk Excel; tanpa `zip` impor/ekspor Excel dinonaktifkan, CSV tetap bisa)
- MySQL/MariaDB (database kosong sudah dibuat; tabel dibuat otomatis)

## Struktur
```
php/
├─ app/                  ← kode server (dilindungi .htaccess deny)
│  ├─ config.example.php    → salin jadi config.php
│  ├─ Web.php Svc.php Analytics.php Xlsx.php Letters.php   ← antarmuka web
│  ├─ pages/                ← satu berkas per halaman
│  └─ Core.php Db.php Repo.php Rules.php Backup.php Api.php Access.php bootstrap.php
├─ public/               ← web root
│  ├─ index.php             → front controller (/?p=dashboard, …)
│  ├─ assets/app.css app.js
│  ├─ api/index.php         → API JSON opsional (/api/*), tidak dipakai antarmuka web
│  └─ .htaccess router.php
└─ cli/backup.php seed_demo.php
```

## Deploy (cPanel)
1. Unggah isi `php/public/` ke `public_html/` (termasuk `.htaccess`, `assets/`, `api/`).
2. Unggah folder `php/app/` **sejajar** dengan `public_html` (lebih aman) atau ke dalam `public_html/app/` (tetap aman: `app/.htaccess` menolak akses).
3. Salin `app/config.example.php` → `app/config.php`; isi kredensial MySQL serta `admin_username` & `admin_password` (min. 10 karakter).
4. Buka situs → login admin. Tabel dibuat otomatis. **Hapus `admin_password` dari `config.php`** lalu ganti password.
5. Di **Admin Panel**: isi Pengaturan Sekolah → tambah **Kelas & Mapel** → **impor siswa** → tambah akun guru (set wali kelas) → Pasangan Mapel.
6. (Opsional) cron backup: `0 2 * * * /usr/bin/php /home/USER/cli/backup.php` (atau andalkan backup otomatis saat login, tiap 24 jam).

Ingin mencoba dengan data contoh? `php cli/seed_demo.php` (hanya jika belum ada kelas) — mencetak password akun contoh.

## Uji lokal
```bash
cd php
DB_DRIVER=sqlite ADMIN_USERNAME=admin ADMIN_PASSWORD=RahasiaKuat123 php -S 127.0.0.1:8080 -t public public/router.php
# lalu buka http://127.0.0.1:8080
```

## Pengganti fitur Google
Integrasi Google (login Google, Google Docs, Google Sheets) memakai OAuth di sisi browser dan tidak bisa dibawa apa adanya ke PHP murni. Penggantinya:
- **Surat** (peringatan, panggilan orang tua, laporan semester) → halaman siap cetak (`Ctrl+P` → *Simpan sebagai PDF*), kata-kata sama seperti versi lama. Alamat/NIP tidak diisi otomatis (dulu berupa contoh palsu); nama Kepsek/BK diambil dari Pengaturan.
- **Google Sheets** → unduh **Excel (.xlsx)** (bisa dibuka/diunggah ke Google Sheets).

## Migrasi dari versi Node/React
Arahkan `config.php` ke database MySQL yang sama — skema identik. Folder `src/`, `server/`, `server.ts` (React/Node) tidak dipakai lagi dan bisa dihapus setelah Anda yakin.

## Keamanan
CSRF pada semua form POST, cookie `HttpOnly` + `SameSite=Lax` (+`Secure` di HTTPS), semua output di-escape, query berparameter (PDO), token delegasi/wali acak 192-bit, halaman publik dikirim `noindex`.
Pasang **HTTPS** di hosting. Jika di balik proxy/Cloudflare set `trust_proxy => true`.

## Nginx (tanpa .htaccess)
```nginx
root /var/www/php/public;
index index.php;
location /api/ { try_files $uri /api/index.php$is_args$args; }
location / { try_files $uri $uri/ /index.php$is_args$args; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location ~ ^/app/ { deny all; }
```
