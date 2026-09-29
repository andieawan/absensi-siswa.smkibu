<?php

namespace App\Http\Controllers;

use App\Models\SchoolSetting;
use App\Models\User;
use App\Services\Audit;
use App\Services\Sequence;
use App\Support\Passwords;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Instalasi lewat browser untuk hosting tanpa SSH: membuat tabel + akun Administrator pertama
 * dari ADMIN_USERNAME/ADMIN_PASSWORD di .env. Otomatis nonaktif begitu sudah ada akun.
 */
class InstallController extends Controller
{
    private function installed(): bool
    {
        try {
            return Schema::hasTable('users') && User::query()->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function show()
    {
        abort_if($this->installed(), 404);

        return view('install', ['ready' => strlen((string) config('absensi.admin.password')) >= 10 && config('absensi.admin.username')]);
    }

    public function run()
    {
        abort_if($this->installed(), 404);
        $username = strtolower(trim((string) config('absensi.admin.username')));
        $password = (string) config('absensi.admin.password');
        if ($username === '' || strlen($password) < 10) {
            return back()->with('error', 'Isi ADMIN_USERNAME dan ADMIN_PASSWORD (minimal 10 karakter) di file .env terlebih dahulu.');
        }
        Artisan::call('migrate', ['--force' => true]);
        $this->createAdmin($username, $password);

        return redirect()->route('login')->with('success', 'Instalasi selesai. Silakan login, lalu hapus ADMIN_PASSWORD dari .env dan ganti password Anda.');
    }

    public static function createAdmin(string $username, string $password): User
    {
        Sequence::atLeast('users', 10);
        $u = User::create([
            'username' => $username, 'password_hash' => Passwords::make($password), 'nama' => config('absensi.admin.nama'),
            'roles' => ['superadmin', 'admin'], 'subjects' => [], 'classes' => [], 'is_active' => true,
        ]);
        if (! SchoolSetting::find(1)) {
            SchoolSetting::put(['school_name' => config('absensi.school_name'), 'tahun_ajaran' => '', 'semester' => 'Ganjil', 'kepsek_nama' => '', 'bk_nama' => '', 'backup_retention_weeks' => 8]);
        }
        Audit::log('Instalasi', 'Sistem', 'Sistem', "Akun Administrator pertama \"$username\" dibuat");

        return $u;
    }
}
