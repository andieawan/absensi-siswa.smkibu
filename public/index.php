<?php
declare(strict_types=1);

// Front controller aplikasi web. Halaman: /?p=dashboard|absensi|nilai|siswa|bk|admin|password|login
// Halaman publik: /?p=delegasi&token=kk_... (ketua kelas) dan /?p=wali&token=wm_... (wali murid)
$appDir = null;
foreach ([dirname(__DIR__) . '/app', __DIR__ . '/app'] as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $appDir = $candidate;
        break;
    }
}
if ($appDir === null) {
    http_response_code(500);
    exit('Folder app tidak ditemukan.');
}
require $appDir . '/bootstrap.php';

set_exception_handler(function (Throwable $e) {
    error_log('[absensi-siswa] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kesalahan</title>'
        . '<body style="font:15px system-ui;max-width:560px;margin:10vh auto;padding:0 16px"><h1>Terjadi kesalahan pada server</h1>'
        . '<p>Coba muat ulang halaman. Jika berlanjut, hubungi administrator (periksa konfigurasi database di <code>app/config.php</code>).</p>'
        . (Config::get('debug') ? '<pre style="white-space:pre-wrap;background:#f1f5f9;padding:10px">' . htmlspecialchars($e->getMessage()) . '</pre>' : '')
        . '</body>';
});

// Kompatibilitas tautan lama: /?token=kk_... dan /?wali=wm_...
$page = $_GET['p'] ?? '';
if ($page === '' && isset($_GET['token'])) $page = 'delegasi';
if ($page === '' && isset($_GET['wali'])) $page = 'wali';
if ($page === '') $page = 'dashboard';

$routes = [
    'login' => 'login', 'logout' => 'login', 'dashboard' => 'dashboard', 'absensi' => 'absensi', 'nilai' => 'nilai',
    'siswa' => 'siswa', 'bk' => 'bk', 'admin' => 'admin', 'password' => 'password', 'delegasi' => 'delegasi',
    'wali' => 'wali', 'export' => 'export', 'surat' => 'surat',
];
if (!is_string($page) || !isset($routes[$page])) {
    http_response_code(404);
    Web::boot();
    Web::head('Halaman Tidak Ditemukan', '', true);
    echo '<div class="card"><h1>Halaman tidak ditemukan</h1><p><a href="' . h(Web::url('dashboard')) . '">Kembali ke dashboard</a></p></div>';
    Web::foot();
    exit;
}

Web::boot();
if (!in_array($page, ['login', 'logout', 'delegasi', 'wali'], true)) {
    Web::requireLogin();
}
if (Web::isPost() && Web::user() && $page !== 'login') {
    Web::verifyPost();
}
require $appDir . '/pages/' . $routes[$page] . '.php';
