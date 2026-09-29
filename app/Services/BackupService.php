<?php

namespace App\Services;

use App\Models\SchoolSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Backup database: MySQL → dump SQL murni PHP (tanpa exec, cocok shared hosting); SQLite → VACUUM INTO. */
class BackupService
{
    private const SKIP = ['sessions', 'login_attempts', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];

    public static function dir(): string
    {
        return rtrim((string) config('absensi.backup_dir'), '/');
    }

    /** @return string[] nama berkas, terbaru dulu */
    public static function files(): array
    {
        $f = array_map('basename', glob(self::dir().'/absensi_*') ?: []);
        rsort($f);

        return array_values(array_filter($f, fn ($n) => preg_match('/\.(sql|sqlite3)$/', $n)));
    }

    public static function path(string $file): ?string
    {
        $file = basename($file);
        $p = self::dir().'/'.$file;

        return preg_match('/^absensi_[0-9T-]+\.(sql|sqlite3)$/', $file) && is_file($p) ? $p : null;
    }

    /** @return array{success:bool, file?:string, error?:string} */
    public static function run(): array
    {
        try {
            $dir = self::dir();
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException("Folder backup tidak bisa dibuat: $dir");
            }
            @file_put_contents("$dir/.htaccess", "Require all denied\n");
            $base = gmdate('Y-m-d\TH-i-s');
            $stamp = $base;
            for ($n = 2; glob("$dir/absensi_$stamp.*"); $n++) {
                $stamp = "$base-$n";
            }
            $file = DB::connection()->getDriverName() === 'sqlite' ? self::sqlite($dir, $stamp) : self::mysql($dir, $stamp);
            $s = SchoolSetting::current();
            self::prune($dir, (int) ($s->backup_retention_weeks ?: 8));
            SchoolSetting::put(['last_backup_date' => now('UTC')->format('Y-m-d H:i:s'), 'last_backup_status' => 'success']);

            return ['success' => true, 'file' => basename($file)];
        } catch (Throwable $e) {
            Log::error('[Backup] '.$e->getMessage());
            try {
                SchoolSetting::put(['last_backup_status' => 'failed']);
            } catch (Throwable) {
            }

            return ['success' => false, 'error' => config('app.debug') ? $e->getMessage() : 'Gagal membuat backup database.'];
        }
    }

    public static function runIfDue(int $hours = 24): void
    {
        if (! config('absensi.auto_backup')) {
            return;
        }
        $last = SchoolSetting::current()->last_backup_date;
        if ($last && time() - strtotime($last.' UTC') < $hours * 3600) {
            return;
        }
        self::run();
    }

    private static function sqlite(string $dir, string $stamp): string
    {
        if (DB::transactionLevel() === 0) {
            $file = "$dir/absensi_$stamp.sqlite3";
            DB::statement("VACUUM INTO '".str_replace("'", "''", $file)."'");

            return $file;
        }
        // Di dalam transaksi VACUUM tidak diizinkan → dump SQL biasa.
        $file = "$dir/absensi_$stamp.sql";
        $pdo = DB::connection()->getPdo();
        $fh = fopen($file, 'wb') ?: throw new \RuntimeException("Tidak bisa menulis $file");
        foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_ASSOC) as $t) {
            if (in_array($t['name'], self::SKIP, true)) {
                continue;
            }
            fwrite($fh, "DROP TABLE IF EXISTS \"{$t['name']}\";\n{$t['sql']};\n");
            foreach ($pdo->query("SELECT * FROM \"{$t['name']}\"")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                fwrite($fh, "INSERT INTO \"{$t['name']}\" VALUES (".implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).");\n");
            }
        }
        fclose($fh);

        return $file;
    }

    private static function mysql(string $dir, string $stamp): string
    {
        $file = "$dir/absensi_$stamp.sql";
        $pdo = DB::connection()->getPdo();
        $fh = fopen($file, 'wb') ?: throw new \RuntimeException("Tidak bisa menulis $file");
        try {
            fwrite($fh, '-- Backup absensi-siswa '.gmdate('c')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                if (in_array($t, self::SKIP, true)) {
                    continue;
                }
                $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(\PDO::FETCH_NUM)[1];
                fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n$create;\n\n");
                $st = $pdo->query("SELECT * FROM `$t`");
                $batch = [];
                $cols = null;
                while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                    $cols ??= '`'.implode('`, `', array_keys($row)).'`';
                    $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)).')';
                    if (count($batch) >= 200) {
                        fwrite($fh, "INSERT INTO `$t` ($cols) VALUES\n".implode(",\n", $batch).";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    fwrite($fh, "INSERT INTO `$t` ($cols) VALUES\n".implode(",\n", $batch).";\n");
                }
                fwrite($fh, "\n");
            }
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($fh);
        }

        return $file;
    }

    private static function prune(string $dir, int $weeks): void
    {
        $cutoff = time() - max(1, $weeks) * 7 * 86400;
        foreach (glob("$dir/absensi_*") ?: [] as $f) {
            if (preg_match('/\.(sql|sqlite3)$/', $f) && @filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
