<?php
// Router untuk server pengembangan PHP: php -S 127.0.0.1:8080 -t public public/router.php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/api/') || $path === '/api') {
    require __DIR__ . '/api/index.php';
    return true;
}
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // sajikan berkas statis (assets)
}
require __DIR__ . '/index.php';
return true;
