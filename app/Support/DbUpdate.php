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

    /** Modul TU (mutasi, register surat, absensi guru) siap dipakai. */
    public static function tuReady(): bool
    {
        $s = \Illuminate\Support\Facades\Schema::class;

        return $s::hasTable('tu_surat') && $s::hasTable('mutasi_siswa') && $s::hasTable('staff_attendance');
    }

    /** Absen mandiri guru & staf siap (kolom jam & pengaturan sudah ada). */
    public static function selfReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $s = \Illuminate\Support\Facades\Schema::class;
            $ok = $s::hasColumn('staff_attendance', 'jam_masuk') && $s::hasColumn('school_settings', 'staff_mandiri');
        }

        return $ok;
    }

    /** Tautan info kehadiran kelas untuk wali murid siap dipakai. */
    public static function boardReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasTable('class_board_tokens');
        }

        return $ok;
    }

    /** Kunci API (integrasi aplikasi lain) siap dipakai. */
    public static function apiReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasTable('api_keys');
        }

        return $ok;
    }

    /** Kebijakan password (kolom pw_* di pengaturan sekolah) sudah ada. */
    public static function pwReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasColumn('school_settings', 'pw_mode');
        }

        return $ok;
    }

    /** Daftar jurusan (tabel `jurusan`) siap dipakai. */
    public static function jurusanReady(): bool
    {
        static $ok = null;
        if ($ok === null || app()->runningUnitTests()) {
            $ok = \Illuminate\Support\Facades\Schema::hasTable('jurusan');
        }

        return $ok;
    }
}
