<?php

namespace Database\Seeders;

use App\Http\Controllers\InstallController;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Membuat akun Administrator pertama dari ADMIN_USERNAME/ADMIN_PASSWORD (.env) bila belum ada akun. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->exists()) {
            $this->command?->info('Sudah ada akun — Administrator pertama tidak dibuat ulang.');

            return;
        }
        $u = strtolower(trim((string) config('absensi.admin.username')));
        $p = (string) config('absensi.admin.password');
        if ($u === '' || strlen($p) < 10) {
            $this->command?->warn('Isi ADMIN_USERNAME dan ADMIN_PASSWORD (min. 10 karakter) di .env, atau jalankan: php artisan absensi:admin <username>');

            return;
        }
        InstallController::createAdmin($u, $p);
        $this->command?->info("Akun Administrator \"$u\" dibuat. Hapus ADMIN_PASSWORD dari .env setelah login pertama.");
    }
}
