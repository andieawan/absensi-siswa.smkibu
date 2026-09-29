<?php
declare(strict_types=1);

// Front controller untuk seluruh /api/*. Folder "app" boleh berada di samping
// folder web (lebih aman) atau di dalamnya.
$appDir = null;
foreach ([dirname(__DIR__, 2) . '/app', dirname(__DIR__) . '/app'] as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $appDir = $candidate;
        break;
    }
}
if ($appDir === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    exit('{"success":false,"error":"Folder app tidak ditemukan."}');
}
require $appDir . '/bootstrap.php';

Req::init();
Api::dispatch();
