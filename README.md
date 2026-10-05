# Absensi Siswa — SMK Islam Bustanul Ulum Pakusari (Laravel 13)

Aplikasi absensi & nilai siswa berbasis **Laravel 13** (PHP 8.3+). Halaman dirender di server dengan Blade;
JavaScript hanya sedikit (tanpa npm/Vite, tanpa langkah build). Database MySQL/MariaDB (disarankan) atau SQLite.

## Fitur
| Menu | Isi |
|---|---|
| **Login** | Sesi Laravel, batas 10 gagal / 15 menit, **Ganti Akun** (verifikasi ulang password), ganti password (sesi di perangkat lain otomatis keluar) |
| **Dashboard** | Wali Kelas · Per Mapel · Sekolah (Kepsek). KPI H/I/S/A, ringkasan & saran otomatis, tren, pola absen berkala (hari sama, jarak 10–18 hari), daftar "Perlu Perhatian" + sinyal nilai turun. Wali kelas melihat status "sudah/belum diisi hari ini"; Kepsek/BK/Admin melihat "x/y kelas sudah absen" |
| **Pantau** | Kelas mana yang sudah/belum mengisi absen harian pada suatu tanggal (filter "Belum mengisi"), lengkap dengan wali kelas, jumlah H/I/S/A, jam terakhir diisi |
| **Rekap Rapor** | Jumlah Sakit / Izin / Tanpa Keterangan per siswa per semester (absen harian), rentang tanggal bisa diubah, unduh Excel & cetak — siap disalin ke e-Rapor. Wali kelas: kelasnya; BK/Kepsek/Admin: semua kelas |
| **Absensi** | Harian (wali) / per mapel, tombol H·I·S·A + catatan, simpan ulang = update, peringatan 85%, riwayat sesi (hapus ≤ 7 hari), **tautan delegasi Ketua Kelas** (maks. 24 jam), unduh Excel |
| **Nilai** | Input/edit kegiatan (angka 0–100 / huruf A–E), daftar kegiatan (edit/hapus ≤ 7 hari sejak diinput; setelah itu **nilai susulan** tetap bisa diisi untuk siswa yang belum punya nilai). Nilai kosong = belum mengumpulkan (tidak dihitung rata-rata), rekap + rata-rata, Excel, laporan cetak |
| **Riwayat Siswa** | Profil, kontak orang tua + ringkasan via WhatsApp, kehadiran, pola, uji pengesahan 85% (dispensasi Admin/Kepsek), log absen, nilai, catatan BK, **Portal Wali Murid** (buat/cabut), surat peringatan & panggilan |
| **BK (terpadu)** | Ringkasan · **Presensi BK** (absen manual, tautan ketua kelas untuk kelas mana pun, rekap per kelas) · **Pelanggaran** (tanpa poin; Ringan/Sedang/Berat; sanksi manual) · **Buku Kasus** (bisa "Sangat Rahasia") · **Prestasi** · **Home Visit** · **Riwayat Surat** (status Proses/Selesai + unggah scan/foto kamera). Form memakai pilihan Kelas → Nama siswa |
| **Tata Usaha (TU)** | **Data Siswa** (tambah/ubah/impor + kontak orang tua) · **Mutasi** siswa masuk (pindahan/baru) & keluar (pindah/berhenti/dikeluarkan; status siswa ikut berubah, surat keterangan pindah bernomor otomatis) · **Surat Keluar** dengan nomor otomatis (`001/KET/SMKIBU/X/2026`) termasuk Surat Keterangan Siswa Aktif, Keterangan Pindah, Dispensasi, dan Izin siap cetak · **Surat Masuk** (agenda, status Baru/Diproses/Selesai, disposisi, scan/foto) · **Absensi Guru & Staf** (H/I/S/A/Dinas Luar, jam masuk/pulang, terlambat, rekap bulanan Excel & cetak) · **Cetak**: daftar hadir kosong per bulan, daftar siswa, kartu siswa |
| **WhatsApp gratis** | Tombol `wa.me` — tanpa gateway/API berbayar: pesan disiapkan aplikasi, WhatsApp di HP/laptop guru terbuka, guru tinggal menekan kirim. Ada di halaman Absensi ("Kabari Orang Tua" untuk siswa I/S/A), Riwayat Siswa, catatan BK, dan tautan ketua kelas/portal wali |
| **Admin Panel** | Akun guru · Data siswa (+ nama & No. HP orang tua, impor xlsx/csv) · Kelas & Mapel · Pasangan Guru–Mapel–Kelas · Upload Hardcopy (template → pratinjau → simpan) · **Kenaikan Kelas** (naik/tinggal/lulus per siswa + tahun ajaran baru, backup otomatis) · Log Aktivitas · Pengaturan & Backup |
| **Publik** | `/presensi/{token}` (Ketua Kelas, tanpa login) dan `/wali/{token}` (Wali Murid, baca-saja). Tautan lama `/?token=` & `/?wali=` tetap berfungsi |

Aturan bisnis: batas ubah/hapus 7 hari (non-admin; absensi dihitung dari tanggal sesi, nilai dari waktu input), satu kelas satu wali kelas, syarat kehadiran 85%, otorisasi guru (wali kelas untuk absen harian;
pasangan atau kelas+mapel yang diampu untuk absen mapel/nilai), token delegasi ≤ 24 jam, audit log 500 baris terakhir.

**Absen mandiri guru & staf** (menu *Absen Saya* / kartu di beranda): guru, BK, kepsek, dan TU absen **masuk** dan **pulang** sendiri dari HP, atau melapor **Izin / Sakit / Dinas Luar** (alasan wajib). Hanya untuk hari ini dan sekali per hari; jam memakai waktu server (WIB), bukan jam HP. Masuk setelah *batas jam masuk* ditandai **terlambat** (muncul di rekap bulanan). Bila TU sudah mencatat status hari itu, yang bersangkutan tidak dapat mengubahnya sendiri (koreksi lewat TU). Pengaturan di Admin → Pengaturan: aktif/nonaktif, batas jam, dan **radius lokasi** (opsional): buka halaman itu saat berada di sekolah, klik *Pakai lokasi saya sekarang*, isi radius (mis. 150 m). Absen masuk/pulang lalu hanya diterima bila GPS HP berada dalam radius (gratis, tanpa layanan peta). Pengecekan lokasi memerlukan situs **https://**; kosongkan radius bila tidak diperlukan. Perlu klik *Perbarui Database Sekarang* setelah unggah versi ini.

Hak akses modul TU: peran **TU** (dan Admin/Superadmin) dapat mengisi semua fitur TU; **Kepala Sekolah** hanya melihat
(register surat, rekap absensi guru, cetak). TU **tidak** dapat membuka Admin Panel, modul BK, kenaikan kelas, atau mengubah
absensi/nilai siswa. Absensi guru & staf mengikuti aturan 7 hari (Admin dapat mengubah lebih lama); yang diabsen adalah akun aktif
berperan Guru, Guru BK, Kepala Sekolah, atau TU (akun teknis Administrator tidak ikut). Singkatan pada nomor surat diatur lewat
`KODE_SURAT` di `.env` (bawaan `SMKIBU`). Nomor agenda berulang dari 1 tiap tahun, terpisah untuk surat masuk dan keluar.

Hak akses modul BK: **Guru BK & Superadmin** mencatat/mengubah semua; **Kepsek & Admin** melihat semua (baca saja);
**Wali Kelas** melihat catatan kelasnya dan boleh mencatat **Prestasi** siswanya. Kasus "Sangat Rahasia" hanya
ditampilkan detailnya untuk Guru BK — yang lain hanya melihat bahwa ada kasus. Scan surat disimpan di
`storage/app/private/bk-surat` (tidak bisa dibuka langsung dari internet).

**Mapel mengikuti kelas.** Di form Absensi & Nilai, daftar Mata Pelajaran hanya berisi mapel yang diajar guru di
kelas yang dipilih. Bila mapel berbeda tiap kelas (mis. B. Indonesia di X DKV 1, B. Daerah di X DKV 2), atur di
Admin → **Pasangan Mapel**; mapel/kelas yang dicentang di akun guru berlaku silang (semua mapel di semua kelas itu).

**Riwayat Siswa dibatasi.** Guru mapel hanya melihat kelas & siswa yang diajarnya (plus kelas perwaliannya); membuka siswa
kelas lain ditolak (403). Admin, Kepala Sekolah, BK, dan TU tetap melihat semua kelas.

## Struktur kode
```
app/
├─ Http/Controllers/        Dashboard, Attendance, Grade, Student, Bk, BkRecord, Monitor, Recap, Letter, Export, Public, Auth, Install
│  ├─ Admin/                Teacher, Student, Master (kelas & mapel), Pairing, Hardcopy, Promotion, Log, Setting
│  └─ Tu/                   Home, Mutasi, Surat, Staff (absensi guru), Print (cetak dokumen)
├─ Models/                  User, SchoolClass, Subject, Student, Attendance, GradeActivity, GradeValue, Pairing, …
├─ Services/                Rules (aturan bisnis), Analytics, AttendanceService, GradeService, AccessService,
│                           AdminService, BackupService, Sequence, Audit, Assignments, BkService, SchoolReports, TuService, StaffService
└─ Support/                 Dates, Passwords, Xlsx (baca/tulis .xlsx tanpa library luar), WhatsApp, TahunAjaran, BkModules
config/absensi.php          pengaturan khusus aplikasi
database/migrations/        skema (tabel yang sudah ada dilewati → DB versi lama langsung terpakai)
database/seeders/           DatabaseSeeder (admin pertama), DemoSeeder (data contoh)
resources/views/            Blade: layouts, dashboard, attendance, grades, students, bk, admin, public, letters
routes/web.php, console.php rute web; perintah absensi:backup, absensi:admin; jadwal backup harian
tests/                      89 tes PHPUnit (fitur & aturan bisnis)
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
3. Pindahkan isi `absensi/public/` ke `public_html/`, lalu ubah tiga baris di `public_html/index.php`:
   `__DIR__.'/../storage/…'` → `__DIR__.'/../absensi/storage/…'`, `__DIR__.'/../vendor/…'` → `__DIR__.'/../absensi/vendor/…'`,
   `__DIR__.'/../bootstrap/app.php'` → `__DIR__.'/../absensi/bootstrap/app.php'`.
   (Atau, bila cPanel mengizinkan, cukup ubah document root domain ke `absensi/public`.)
4. Salin `.env.example` → `.env`; isi `APP_KEY` (buat dengan `php artisan key:generate --show` di komputer mana pun, atau
   `base64:` + 32 byte acak), `DB_*`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`.
5. Buka **`https://domain-anda/pasang`** → klik *Pasang Sekarang*. Tabel dibuat dan akun admin pertama dibuat.
   Halaman ini otomatis tertutup begitu sudah ada akun.

## Memperbarui ke versi baru (cPanel tanpa SSH)
Unggah/timpa berkas aplikasi (kecuali `.env` dan `storage/`), lalu login sebagai Admin → **Pengaturan** → klik
**Perbarui Database Sekarang** (muncul otomatis bila ada perubahan struktur; backup dibuat lebih dulu).
Dengan SSH cukup `php artisan migrate --force`.

## Aplikasi di HP (PWA)
Aplikasi bisa dipasang ke layar utama HP/laptop seperti aplikasi biasa (ikon sendiri, layar penuh, pintasan "Isi Absensi" & "Input Nilai"):
- **Android / Chrome / Edge:** buka situs → tombol **Pasang Aplikasi** (di halaman login atau menu atas), atau menu ⋮ → *Instal aplikasi*.
- **iPhone / iPad (Safari):** tombol Bagikan → *Tambahkan ke Layar Utama*.

Syarat: situs memakai **HTTPS**. Halaman selalu diambil langsung dari server (data siswa tidak disimpan di HP); bila tidak ada
internet, muncul halaman "Tidak ada koneksi". Nama aplikasi mengikuti nama sekolah di Pengaturan. Setelah mengubah
`public/sw.js`, naikkan `VERSION` di dalamnya agar HP mengambil versi baru.

## Pindah dari versi lama (React/Node atau PHP murni)
Arahkan `.env` ke database MySQL yang sama lalu jalankan `php artisan migrate` (atau `/pasang` tidak diperlukan karena
sudah ada akun). Nama tabel & kolom **tidak berubah**, tabel yang sudah ada dilewati. Password lama (format
`sha256:salt:hash`) tetap bisa dipakai login dan otomatis diganti ke bcrypt saat login berhasil. Tabel lama `sessions`
dan `login_attempts` tidak dipakai lagi (sesi disimpan di `storage/`) dan boleh dihapus.

## Impor siswa
Kolom wajib: **NIS, Nama Siswa, JK, Kelas**. Kolom opsional: **Nama Ortu**, **No HP Ortu** (format bebas: `0812…`,
`+62 812…`, `812…` — disimpan sebagai `62812…`). Nomor orang tua juga bisa diisi/diubah satu per satu di Admin → Data Siswa.

## Akhir tahun ajaran
Admin → **Kenaikan Kelas**: luluskan kelas XII dulu, lalu naikkan XI → XII dan X → XI (hilangkan centang siswa yang
tinggal kelas), kemudian klik **Mulai Tahun Ajaran Baru**. Riwayat absensi, nilai, dan catatan BK tetap tersimpan;
catatan BK menyimpan kelas saat kejadian. Setelah itu atur ulang Wali Kelas di Akun Guru bila berganti.

## Perintah berguna
| Perintah | Fungsi |
|---|---|
| `php artisan absensi:admin nama.user` | Buat akun Administrator baru (password ditanya) |
| `php artisan absensi:backup` | Backup sekarang (MySQL → `.sql`, SQLite → salinan file) ke `storage/app/backups` |
| `php artisan db:seed --class=DemoSeeder` | Data contoh (hanya jika belum ada kelas) — mencetak password akun contoh. Tanpa terminal: Admin → Pengaturan → **Isi Data Contoh** |
| `vendor/bin/phpunit` | Jalankan 89 tes otomatis (SQLite in-memory) |

## Pengganti fitur Google
Login Google, Google Docs, dan Google Sheets dari versi React tidak dipakai. Penggantinya: **surat siap cetak**
(`Ctrl+P` → *Simpan sebagai PDF*; nama Kepsek/BK diambil dari Pengaturan, alamat/NIP diisi manual) dan **unduhan Excel (.xlsx)**.

## Keamanan
CSRF & escaping bawaan Laravel, password bcrypt, sesi dicabut saat password diganti/direset atau akun dinonaktifkan,
pembatasan laju login & halaman publik, token delegasi/wali acak 192-bit, header keamanan (X-Frame-Options, nosniff).
Gunakan **HTTPS** dan `APP_DEBUG=false` di produksi. Di balik Cloudflare/proxy set `TRUST_PROXIES=true`.
