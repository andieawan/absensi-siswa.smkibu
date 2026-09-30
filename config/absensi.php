<?php

// Konfigurasi khusus aplikasi absensi.
return [
    'school_name' => env('SCHOOL_NAME', 'SMK Islam Bustanul Ulum Pakusari'),
    'timezone' => env('ABSENSI_TIMEZONE', 'Asia/Jakarta'), // untuk "hari ini" & tanggal surat; data disimpan UTC
    'auto_backup' => (bool) env('ABSENSI_AUTO_BACKUP', true),
    'backup_dir' => env('ABSENSI_BACKUP_DIR', storage_path('app/backups')),
    'admin' => [
        'username' => env('ADMIN_USERNAME', ''),
        'password' => env('ADMIN_PASSWORD', ''),
        'nama' => env('ADMIN_NAMA', 'Administrator'),
    ],
    'trust_proxies' => (bool) env('TRUST_PROXIES', false),
    'edit_window_days' => 7,     // batas ubah/hapus non-admin
    'min_attendance' => 85.0,    // syarat kehadiran minimal (%)
    'kode_surat' => env('KODE_SURAT', 'SMKIBU'), // singkatan sekolah pada nomor surat: 001/KET/SMKIBU/X/2026
    'bk_subject_id' => 5,        // mapel "Bimbingan Konseling" disembunyikan dari daftar mapel reguler
];
