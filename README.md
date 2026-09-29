# Absensi Siswa — SMK Islam Bustanul Ulum Pakusari (Laravel 13)

Aplikasi absensi & nilai siswa berbasis **Laravel 13** (PHP 8.3+). Halaman dirender di server dengan Blade;
JavaScript hanya sedikit (tanpa npm/Vite, tanpa langkah build). Database MySQL/MariaDB (disarankan) atau SQLite.

## Fitur
| Menu | Isi |
|---|---|
| **Login** | Sesi Laravel, batas 10 gagal / 15 menit, **Ganti Akun** (verifikasi ulang password), ganti password (sesi di perangkat lain otomatis keluar) |
| **Dashboard** | Wali Kelas · Per Mapel · Sekolah (Kepsek). KPI H/I/S/A, ringkasan & saran otomatis, tren, pola absen berkala (hari sama, jarak 10–18 hari), daftar "Perlu Perhatian" + sinyal nilai turun |
| **Absensi** | Harian (wali) / per mapel, tombol H·I·S·A + catatan, simpan ulang = update, peringatan 85%, riwayat sesi (hapus ≤ 7 hari), **tautan delegasi Ketua Kelas** (maks. 24 jam), unduh Excel |
| **Nilai** | Input/edit kegiatan (angka 0–100 / huruf A–E), daftar kegiatan (hapus ≤ 7 hari), rekap + rata-rata, Excel, laporan cetak |
| **Riwayat Siswa** | Profil, kehadiran, pola, uji pengesahan 85% (dispensasi Admin/Kepsek), log absen, nilai, **Portal Wali Murid** (buat/cabut), surat peringatan & panggilan |
| **Integrasi BK** | Input absensi manual BK, rekap ketidakhadiran per kelas, Excel |
| **Admin Panel** | Akun guru · Data siswa (+ impor xlsx/csv) · Kelas & Mapel · Pasangan Guru–Mapel–Kelas · Upload Hardcopy (template → pratinjau → simpan) · Log Aktivitas · Pengaturan & Backup |
| **Publik** | `/presensi/{token}` (Ketua Kelas, tanpa login) dan `/wali/{token}` (Wali Murid, baca-saja). Tautan lama `/?token=` & `/?wali=` tetap berfungsi |

Aturan bisnis: batas ubah/hapus 7 hari (non-admin), syarat kehadiran 85%, otorisasi guru (wali kelas untuk absen harian;
pasangan atau kelas+mapel yang diampu untuk absen mapel/nilai), token delegasi ≤ 24 jam, audit log 500 baris terakhir.

## Struktur kode
```
app/
├─ Http/Controllers/        Dashboard, Attendance, Grade, Student, Bk, Letter, Export, Public, Auth, Install
│  └─ Admin/                Teacher, Student, Master (kelas & mapel), Pairing, Hardcopy, Log, Setting
├─ Models/                  User, SchoolClass, Subject, Student, Attendance, GradeActivity, GradeValue, Pairing, …
├─ Services/                Rules (aturan bisnis), Analytics, AttendanceService, GradeService, AccessService,
│                           AdminService, BackupService, Sequence, Audit, Assignments
└─ Support/                 Dates, Passwords, Xlsx (baca/tulis .xlsx tanpa library luar)
config/absensi.php          pengaturan khusus aplikasi
database/migrations/        skema (tabel yang sudah ada dilewati → DB versi lama langsung terpakai)
database/seeders/           DatabaseSeeder (admin pertama), DemoSeeder (data contoh)
resources/views/            Blade: layouts, dashboard, attendance, grades, students, bk, admin, public, letters
routes/web.php, console.php rute web; perintah absensi:backup, absensi:admin; jadwal backup harian
tests/                      36 tes PHPUnit (fitur & aturan bisnis)
```

## Kebutuhan
- PHP **8.3+** dengan `pdo_mysql` (atau `pdo_sqlite`), `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `zip` (untuk Excel)
- Composer 2 (atau unggah folder `vendor/` yang sudah jadi)
- MySQL/MariaDB (database kosong sudah dibuat)

## Instalasi (VPS / hosting dengan SSH)
```bash
git clone https://github.com/andieawan/absensi-siswa.smkibu.git absensi && cd absensi
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
# isi DB_* serta ADMIN_USERNAME / ADMIN_PASSWORD (min. 10 karakter) di .env
php artisan migrate --force && php artisan db:seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Arahkan document root web server ke folder **`public/`**. Pastikan `storage/` dan `bootstrap/cache/` dapat ditulis web server.
Setelah login pertama: hapus `ADMIN_PASSWORD` dari `.env`, lalu ganti password lewat menu **Password**.

Cron (backup harian 02.00 WIB lewat penjadwal Laravel):
```
* * * * * cd /path/absensi && php artisan schedule:run >> /dev/null 2>&1
```
Tanpa cron pun backup tetap berjalan otomatis tiap 24 jam saat ada yang login.

## Instalasi di cPanel tanpa SSH
1. Di komputer lain (atau ambil paket rilis yang sudah berisi `vendor/`), jalankan `composer install --no-dev`.
2. Unggah seluruh folder proyek ke `/home/USER/absensi` (**di luar** `public_html`).
3. Pindahkan isi `absensi/public/` ke `public_html/`, lalu ubah dua baris di `public_html/index.php`:
   `__DIR__.'/../storage/…'` → `__DIR__.'/../absensi/storage/…'`, `__DIR__.'/../vendor/…'` → `__DIR__.'/../absensi/vendor/…'`,
   `__DIR__.'/../bootstrap/app.php'` → `__DIR__.'/../absensi/bootstrap/app.php'`.
   (Atau, bila cPanel mengizinkan, cukup ubah document root domain ke `absensi/public`.)
4. Salin `.env.example` → `.env`; isi `APP_KEY` (buat dengan `php artisan key:generate --show` di komputer mana pun, atau
   `base64:` + 32 byte acak), `DB_*`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`.
5. Buka **`https://domain-anda/pasang`** → klik *Pasang Sekarang*. Tabel dibuat dan akun admin pertama dibuat.
   Halaman ini otomatis tertutup begitu sudah ada akun.

## Pindah dari versi lama (React/Node atau PHP murni)
Arahkan `.env` ke database MySQL yang sama lalu jalankan `php artisan migrate` (atau `/pasang` tidak diperlukan karena
sudah ada akun). Nama tabel & kolom **tidak berubah**, tabel yang sudah ada dilewati. Password lama (format
`sha256:salt:hash`) tetap bisa dipakai login dan otomatis diganti ke bcrypt saat login berhasil. Tabel lama `sessions`
dan `login_attempts` tidak dipakai lagi (sesi disimpan di `storage/`) dan boleh dihapus.

## Perintah berguna
| Perintah | Fungsi |
|---|---|
| `php artisan absensi:admin nama.user` | Buat akun Administrator baru (password ditanya) |
| `php artisan absensi:backup` | Backup sekarang (MySQL → `.sql`, SQLite → salinan file) ke `storage/app/backups` |
| `php artisan db:seed --class=DemoSeeder` | Data contoh (hanya jika belum ada kelas) — mencetak password akun contoh |
| `vendor/bin/phpunit` | Jalankan 36 tes otomatis (SQLite in-memory) |

## Pengganti fitur Google
Login Google, Google Docs, dan Google Sheets dari versi React tidak dipakai. Penggantinya: **surat siap cetak**
(`Ctrl+P` → *Simpan sebagai PDF*; nama Kepsek/BK diambil dari Pengaturan, alamat/NIP diisi manual) dan **unduhan Excel (.xlsx)**.

## Keamanan
CSRF & escaping bawaan Laravel, password bcrypt, sesi dicabut saat password diganti/direset atau akun dinonaktifkan,
pembatasan laju login & halaman publik, token delegasi/wali acak 192-bit, header keamanan (X-Frame-Options, nosniff).
Gunakan **HTTPS** dan `APP_DEBUG=false` di produksi. Di balik Cloudflare/proxy set `TRUST_PROXIES=true`.
