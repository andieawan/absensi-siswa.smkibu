<?php
declare(strict_types=1);

// Backup manual/terjadwal:  php cli/backup.php
// Contoh cron harian (cPanel):  0 2 * * *  /usr/bin/php /home/USER/absensi/cli/backup.php
if (PHP_SAPI !== 'cli') {
    exit('Hanya untuk CLI.');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$r = Backup::run();
if ($r['success']) {
    fwrite(STDOUT, "Backup berhasil: {$r['file']}\n");
    exit(0);
}
fwrite(STDERR, 'Backup gagal: ' . ($r['error'] ?? 'tidak diketahui') . "\n");
exit(1);
