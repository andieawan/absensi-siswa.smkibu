# API v1 (hanya baca)

Dipakai aplikasi lain (website sekolah, aplikasi BK, ulangan online, dll.) untuk membaca data. Spesifikasi mesin-baca: `GET /api/v1/openapi.json` (OpenAPI 3).

## Kunci API
1. Admin → **Integrasi API** → *Buat Kunci API Baru*. Isi nama aplikasi, pilih izin (scope), batas permintaan per menit.
2. Kunci (`ak_…`) **hanya tampil sekali**. Di server hanya disimpan hash SHA-256-nya. Hilang = cabut lalu buat baru.
3. **Cabut** kunci kapan saja; efeknya langsung. Satu kunci untuk satu aplikasi.

Kirim di header: `Authorization: Bearer ak_xxx` (atau `X-API-Key: ak_xxx`). Kunci di query string **ditolak**.

## Izin (scope)
| Scope | Akses |
|---|---|
| `kelas:baca` | `/kelas`, `/kelas/{id}` |
| `mapel:baca` | `/mapel` |
| `siswa:baca` | `/siswa`, `/siswa/{id}` (NIS, nama, JK, kelas, status) |
| `siswa:kontak` | menambah `nama_ortu`, `telp_ortu` (62…) pada data siswa — data pribadi, beri hanya bila perlu |
| `kehadiran:baca` | `/kehadiran`, `/siswa/{id}/rekap` (catatan/keterangan absensi tidak ikut) |

Data **nilai** dan **catatan BK** sengaja tidak tersedia.

## Endpoint
- `GET /api/v1/ping` — uji kunci.
- `GET /api/v1/kelas`, `/kelas/{id}`
- `GET /api/v1/mapel`
- `GET /api/v1/siswa?kelas_id=&status=aktif|semua|…&q=` , `/siswa/{id}`
- `GET /api/v1/siswa/{id}/rekap`
- `GET /api/v1/kehadiran?dari=YYYY-MM-DD&sampai=YYYY-MM-DD&jenis=harian|mapel|semua&kelas_id=&siswa_id=&mapel_id=` — default 30 hari terakhir, jenis `harian`; rentang maks 366 hari.

Daftar memakai `page` & `per_page` (default 100, maks 200; kehadiran maks 500). Bentuk respons: `{"data": …, "meta": {"page","per_page","total","last_page"}}`.

## Kesalahan
`{"error":{"code":"…","message":"…"}}` — 401 `unauthorized`, 403 `forbidden`, 404 `not_found`, 422 `invalid_request`, 429 `rate_limited` (lihat header `Retry-After`), 503 `not_ready` (database belum diperbarui).

## Contoh
```
curl -H "Authorization: Bearer ak_xxx" "https://absen.sekolah.sch.id/api/v1/siswa?kelas_id=3&per_page=50"
```

## Catatan operasional
- Pasang HTTPS (kunci dikirim di header). Batas permintaan dihitung per kunci per menit.
- Log Aktivitas mencatat satu baris "Akses API" per kunci per jam; waktu & IP pemakaian terakhir ada di halaman Integrasi API.
- Fitur butuh migrasi: Admin → Pengaturan → *Perbarui Database Sekarang* (atau `php artisan migrate --force`).
