<?php
// Salin file ini menjadi config.php lalu sesuaikan. config.php JANGAN di-commit / dibagikan.
// Setiap nilai juga bisa diberikan lewat environment variable (huruf besar), mis. MYSQL_HOST.
return [
    'db_driver'      => 'mysql',            // 'mysql' (disarankan) atau 'sqlite'
    'mysql_host'     => 'localhost',
    'mysql_port'     => '3306',
    'mysql_user'     => 'absensi',
    'mysql_password' => 'GANTI_PASSWORD_DB',
    'mysql_database' => 'absensi_siswa',   // database harus sudah dibuat; tabel dibuat otomatis
    'mysql_ssl'      => false,

    // Akun Administrator pertama — hanya dipakai saat tabel users masih kosong.
    // Hapus admin_password dari file ini setelah login pertama, lalu ganti password.
    'admin_username' => 'admin',
    'admin_password' => 'GANTI_DENGAN_PASSWORD_KUAT',   // minimal 10 karakter
    'admin_nama'     => 'Administrator',
    'school_name'    => 'SMK Islam Bustanul Ulum Pakusari',

    'auto_backup'    => true,               // backup otomatis (dipicu saat login) tiap 24 jam
    'backup_dir'     => '',                 // kosong = app/storage/backups (dilindungi .htaccess)
    'trust_proxy'    => false,              // true jika di belakang Cloudflare/reverse proxy
    'debug'          => false,              // true hanya saat debugging (menampilkan pesan error)
];
