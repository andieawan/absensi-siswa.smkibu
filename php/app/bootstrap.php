<?php
declare(strict_types=1);

// Bootstrap: muat kelas, konfigurasi, handler error. Dipakai oleh public/api/index.php dan cli/*.php.
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Membutuhkan PHP 8.1 atau lebih baru.');
}

define('APP_DIR', __DIR__);
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

foreach (['Core', 'Db', 'Repo', 'Rules', 'Backup', 'Api', 'Access'] as $f) {
    require_once __DIR__ . "/$f.php";
}

Config::load(APP_DIR . '/config.php');

ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e) {
        error_log('[absensi-siswa] ' . $e);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => Config::get('debug') ? $e->getMessage() : 'Terjadi kesalahan pada server.',
        ], JSON_UNESCAPED_UNICODE);
    });
}
