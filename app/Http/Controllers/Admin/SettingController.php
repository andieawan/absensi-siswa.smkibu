<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AdminService;
use App\Services\Audit;
use App\Services\BackupService;
use App\Support\Dates;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        return view('admin.settings', ['files' => array_slice(BackupService::files(), 0, 10), 'pending' => \App\Support\DbUpdate::pending(), 'canDemo' => ! \App\Models\SchoolClass::query()->exists() && ! \App\Models\Student::query()->exists()]);
    }

    public function update(Request $request)
    {
        AdminService::saveSettings($this->me(), $request->validate([
            'school_name' => 'required|string|max:191', 'tahun_ajaran' => 'nullable|string|max:32', 'semester' => 'required|in:Ganjil,Genap',
            'kepsek_nama' => 'nullable|string|max:191', 'bk_nama' => 'nullable|string|max:191', 'backup_retention_weeks' => 'required|integer|min:1|max:104',
            'pw_form' => 'nullable|boolean', 'pw_mode' => 'nullable|in:aturan,bebas', 'pw_min' => 'nullable|integer|min:1|max:64', 'pw_huruf' => 'nullable|boolean', 'pw_angka' => 'nullable|boolean', 'pw_simbol' => 'nullable|boolean',
            'att_form' => 'nullable|boolean', 'att_alpa' => 'nullable|integer|min:1|max:100', 'att_sakit' => 'nullable|integer|min:1|max:100', 'att_izin' => 'nullable|integer|min:1|max:100', 'att_total' => 'nullable|integer|min:1|max:100',
            'staff_form' => 'nullable|boolean', 'staff_mandiri' => 'nullable|boolean', 'staff_jam_masuk' => 'nullable|date_format:H:i',
            'staff_lat' => 'nullable|numeric|between:-90,90', 'staff_lng' => 'nullable|numeric|between:-180,180', 'staff_radius' => 'nullable|integer|min:0|max:5000',
        ]));

        return back()->with('success', 'Pengaturan sekolah disimpan.');
    }

    /** Isi data contoh dari browser (untuk mencoba aplikasi). Hanya bila belum ada data kelas/siswa. */
    public function demo()
    {
        if (\App\Models\SchoolClass::query()->exists() || \App\Models\Student::query()->exists()) {
            throw new UserError('Data contoh hanya bisa diisi pada aplikasi yang masih kosong (belum ada kelas/siswa).');
        }
        BackupService::run();
        \Database\Seeders\DemoSeeder::$passwords = [];
        (new \Database\Seeders\DemoSeeder)->run();
        Audit::log('Isi Data Contoh', 'Sistem', $this->me()->nama, 'Data contoh dibuat');

        return back()->with('success', 'Data contoh dibuat: 4 kelas, 48 siswa, absensi, nilai, BK, surat, dan absensi guru/staf.')->with('demo_passwords', \Database\Seeders\DemoSeeder::$passwords);
    }

    public function backup()
    {
        $r = BackupService::run();
        if (! $r['success']) {
            throw new UserError($r['error']);
        }
        Audit::log('Backup Manual Database', 'Sistem', $this->me()->nama, 'Snapshot: '.$r['file']);

        return back()->with('success', 'Backup dibuat: '.$r['file']);
    }

    public function download(string $file)
    {
        $path = BackupService::path($file) ?? abort(404);
        Audit::log('Unduh Backup', 'Sistem', $this->me()->nama, basename($path));

        return response()->download($path);
    }

    /** Ekspor seluruh data (tanpa password) sebagai JSON. */
    public function json()
    {
        Audit::log('Unduh Data JSON', 'Sistem', $this->me()->nama, 'Ekspor seluruh data (tanpa password)');
        $data = [
            'exported_at' => Dates::isoUtc(), 'settings' => SchoolSetting::current(), 'classes' => SchoolClass::all(), 'subjects' => Subject::all(),
            'students' => Student::all(), 'users' => User::all(), 'pairings' => Pairing::all(), 'attendance' => Attendance::all(),
            'gradeActivities' => GradeActivity::all(), 'gradeValues' => GradeValue::all(),
        ];

        return response()->streamDownload(fn () => print (json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)), 'absensi_'.Dates::today().'.json', ['Content-Type' => 'application/json']);
    }

    /** Perbarui struktur database setelah mengunggah versi baru (tanpa SSH). Backup dibuat lebih dulu. */
    public function migrate()
    {
        $n = count(\App\Support\DbUpdate::pending());
        if (! $n) {
            return back()->with('success', 'Database sudah versi terbaru.');
        }
        BackupService::run();
        \App\Support\DbUpdate::run();
        \App\Services\Audit::log('Perbarui Database', 'Sistem', $this->me()->nama, "$n migrasi dijalankan");

        return back()->with('success', "Database diperbarui ($n perubahan). Cadangan dibuat sebelum pembaruan.");
    }
}
