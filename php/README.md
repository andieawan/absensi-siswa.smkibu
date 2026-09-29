# Backend PHP — go_absen_siswa

Versi PHP dari backend `server.ts` (Node.js/Express). **Kontrak API identik** (41 endpoint `/api/*`, format
request/response, aturan bisnis, format hash password `sha256:<salt>:<hash>`), jadi **frontend React tidak perlu diubah**
dan database MySQL yang sudah dipakai versi Node bisa langsung dipakai.

Cocok untuk hosting biasa (cPanel/shared hosting, Apache + PHP + MySQL) tanpa Node.js.

## Kebutuhan
- PHP **8.1+** dengan ekstensi `pdo_mysql` (atau `pdo_sqlite`), `mbstring`, `openssl`
- MySQL / MariaDB (database kosong sudah dibuat; tabel dibuat otomatis)
- Apache dengan `mod_rewrite` (Nginx: lihat bagian bawah)

## Struktur
```
php/
├─ app/                 ← kode server (JANGAN bisa diakses web; sudah ada .htaccess deny)
│  ├─ config.example.php   → salin jadi config.php
│  ├─ Core.php Db.php Repo.php Rules.php Api.php Access.php Backup.php bootstrap.php
│  └─ storage/             → backup & file SQLite (di-ignore git)
├─ public/              ← isi folder ini yang diunggah ke web root (public_html)
│  ├─ .htaccess            → rewrite /api/* dan fallback SPA
│  └─ api/index.php        → front controller
└─ cli/backup.php       ← backup terjadwal lewat cron
```

## Cara deploy
1. **Build frontend:** di root proyek jalankan `npm install && npm run build` → hasilnya folder `dist/`.
2. **Unggah ke web root** (`public_html`): seluruh isi `dist/` **dan** isi `php/public/` (termasuk `.htaccess` & folder `api/`).
3. **Unggah folder `php/app/`** sejajar dengan `public_html` (lebih aman), atau ke dalam `public_html/app/`
   (aman juga karena `app/.htaccess` menolak akses langsung).
4. **Buat `app/config.php`** dari `config.example.php`: isi kredensial MySQL, `admin_username` & `admin_password`
   (min. 10 karakter).
5. Buka aplikasi & login sebagai admin pertama. Tabel dibuat otomatis pada request pertama.
   **Hapus `admin_password` dari `config.php`** lalu ganti password lewat menu ganti password.
6. (Opsional) cron backup harian: `0 2 * * * /usr/bin/php /home/USER/php/cli/backup.php`

Frontend memanggil `/api/...` secara relatif, jadi frontend dan API harus satu domain (seperti versi Node).

## Migrasi dari versi Node
- **MySQL:** cukup arahkan `config.php` ke database yang sama — skema identik, hash password kompatibel.
- **SQLite → MySQL:** ekspor tiap tabel dari file `.sqlite3` lalu impor ke MySQL (atau jalankan versi Node dengan
  `DB_DRIVER=mysql` sekali untuk menyalin data), lalu pakai versi PHP.

## Perbedaan dengan versi Node
| Hal | Node | PHP |
|---|---|---|
| Data demo (`SEED_DEMO_DATA`) | ada | **tidak ada** — hanya akun admin pertama dari `config.php` |
| Backup otomatis | `setInterval` tiap 24 jam | dipicu saat login bila backup terakhir > 24 jam, atau via cron `cli/backup.php` |
| Backup MySQL | `mysqldump` | dump SQL murni PHP (tanpa `exec`, jalan di shared hosting) |
| Rate limit login | memori proses | tabel `login_attempts` (10 gagal / 15 menit per IP+username) |
| Sinkronisasi master (`/sync/push`) | tanpa transaksi | dalam satu transaksi (gagal = tidak ada yang tersimpan) |
| Alokasi ID `/sequence/allocate` | sinkron single-thread | `SELECT ... FOR UPDATE` dalam transaksi (aman paralel) |
| Backup tidak menyertakan | — | tabel `sessions` & `login_attempts` (data sementara) |

## Nginx (tanpa .htaccess)
```nginx
location /api/ { try_files $uri /api/index.php$is_args$args; }
location ~ ^/api/index\.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
location / { try_files $uri $uri/ /index.html; }
location ~ ^/app/ { deny all; }
```

## Uji lokal
```bash
cd php
DB_DRIVER=sqlite ADMIN_USERNAME=admin ADMIN_PASSWORD=RahasiaKuat123 php -S 127.0.0.1:8080 -t public public/api/index.php
curl http://127.0.0.1:8080/api/health
```
