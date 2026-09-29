<?php

use App\Http\Controllers\InstallController;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('absensi:backup', function () {
    $r = BackupService::run();
    $r['success'] ? $this->info('Backup dibuat: '.$r['file']) : $this->error($r['error']);

    return $r['success'] ? 0 : 1;
})->purpose('Buat backup database sekarang (MySQL: dump SQL; SQLite: salinan file)');

Artisan::command('absensi:admin {username} {--nama=Administrator}', function (string $username) {
    $username = strtolower(trim($username));
    if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
        $this->error("Username $username sudah ada.");

        return 1;
    }
    $pw = $this->secret('Password (min. 10 karakter)');
    if (strlen((string) $pw) < 10) {
        $this->error('Password minimal 10 karakter.');

        return 1;
    }
    config(['absensi.admin.nama' => $this->option('nama')]);
    InstallController::createAdmin($username, $pw);
    $this->info("Akun Administrator $username dibuat.");
})->purpose('Buat akun Administrator (superadmin)');

// Backup harian — aktif bila cron menjalankan "php artisan schedule:run" tiap menit.
Schedule::command('absensi:backup')->dailyAt('02:00')->timezone(config('absensi.timezone'));
