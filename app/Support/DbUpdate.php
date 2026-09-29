<?php

namespace App\Support;

/** Deteksi & jalankan migrasi yang belum terpasang (untuk hosting tanpa SSH, dari Admin → Pengaturan). */
class DbUpdate
{
    /** @return string[] nama migrasi yang belum dijalankan */
    public static function pending(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [];
        }
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $ran = $migrator->getRepository()->getRan();

        return array_values(array_diff($files, $ran));
    }

    public static function run(): void
    {
        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        foreach (['view:clear', 'route:clear', 'config:clear'] as $c) {
            try {
                \Illuminate\Support\Facades\Artisan::call($c);
            } catch (\Throwable) {
            }
        }
    }

    /** Modul BK siap dipakai (tabel sudah dibuat).  */
    public static function bkReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('bk_records');
    }

    /** Kolom kontak orang tua sudah ada di tabel siswa. */
    public static function parentContactReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('students', 'telp_ortu');
    }
}
