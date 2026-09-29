<?php
declare(strict_types=1);

// ============================================================================
// Backup database (MySQL: dump SQL murni PHP; SQLite: VACUUM INTO).
// PHP tidak punya proses latar belakang seperti setInterval di Node, jadi:
//   1) backup otomatis dipicu saat login bila backup terakhir > 24 jam, dan
//   2) tersedia skrip CLI (cli/backup.php) untuk dijadwalkan lewat cron.
// Dump dibuat dengan PHP murni (tanpa exec/mysqldump) supaya jalan di shared hosting.
// ============================================================================

final class Backup
{
    private const SKIP_TABLES = ['sessions', 'login_attempts']; // data sementara, tidak perlu dibackup

    public static function dir(): string
    {
        return (string) Config::get('backup_dir') ?: APP_DIR . '/storage/backups';
    }

    /** @return array{success:bool, file?:string, error?:string} */
    public static function run(): array
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Folder backup tidak bisa dibuat: $dir");
            }
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");

            $stamp = substr(str_replace([':', '.'], '-', Util::iso()), 0, 19);
            // Dua backup dalam detik yang sama (mis. otomatis saat login + manual) tidak boleh saling menimpa/gagal.
            for ($n = 2; glob("$dir/absensi_$stamp.*"); $n++) {
                $stamp = substr(str_replace([':', '.'], '-', Util::iso()), 0, 19) . "-$n";
            }
            $file = Db::driver() === 'sqlite'
                ? self::sqlite($dir, $stamp)
                : self::mysqlDump($dir, $stamp);

            $settings = Repo::settingsGet();
            self::prune($dir, (int) ($settings['backup_retention_weeks'] ?? 8) ?: 8);
            Repo::settingsUpdate(array_merge($settings, ['last_backup_date' => Util::sqlNow(), 'last_backup_status' => 'success']));
            return ['success' => true, 'file' => $file];
        } catch (Throwable $e) {
            error_log('[Backup] Gagal: ' . $e->getMessage());
            try {
                Repo::settingsUpdate(array_merge(Repo::settingsGet(), ['last_backup_status' => 'failed']));
            } catch (Throwable) {
            }
            return ['success' => false, 'error' => Config::get('debug') ? $e->getMessage() : 'Gagal membuat backup database.'];
        }
    }

    /** Jalankan backup bila backup terakhir sudah lebih dari $hours jam. */
    public static function runIfDue(int $hours = 24): void
    {
        if (!Config::get('auto_backup')) {
            return;
        }
        $last = Repo::settingsGet()['last_backup_date'] ?? null;
        $lastTs = $last ? strtotime($last . ' UTC') : 0;
        if ($lastTs && time() - $lastTs < $hours * 3600) {
            return;
        }
        self::run();
    }

    private static function sqlite(string $dir, string $stamp): string
    {
        $file = "$dir/absensi_$stamp.sqlite3";
        Db::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'");
        return $file;
    }

    private static function mysqlDump(string $dir, string $stamp): string
    {
        $file = "$dir/absensi_$stamp.sql";
        $pdo = Db::pdo();
        $fh = fopen($file, 'wb');
        if (!$fh) {
            throw new RuntimeException("Tidak bisa menulis file backup: $file");
        }
        try {
            fwrite($fh, "-- Backup absensi-siswa " . Util::iso() . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $t) {
                if (in_array($t, self::SKIP_TABLES, true)) {
                    continue;
                }
                $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
                fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
                $st = $pdo->query("SELECT * FROM `$t`");
                $batch = [];
                $cols = null;
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $cols ??= '`' . implode('`, `', array_keys($row)) . '`';
                    $batch[] = '(' . implode(', ', array_map(
                        fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)),
                        $row
                    )) . ')';
                    if (count($batch) >= 200) {
                        fwrite($fh, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    fwrite($fh, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }
        return $file;
    }

    private static function prune(string $dir, int $retentionWeeks): void
    {
        $cutoff = time() - max(1, $retentionWeeks) * 7 * 86400;
        foreach (glob($dir . '/absensi_*') ?: [] as $f) {
            if (preg_match('/\.(sql|sqlite3)$/', $f) && @filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
