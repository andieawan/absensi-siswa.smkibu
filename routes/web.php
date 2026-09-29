<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GradeController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

// ---- PWA (manifest aplikasi yang bisa dipasang di HP) ----
Route::get('/manifest.webmanifest', [\App\Http\Controllers\PwaController::class, 'manifest'])->name('manifest');

// ---- Instalasi pertama (hanya aktif selama belum ada akun) ----
Route::get('/pasang', [InstallController::class, 'show'])->name('install');
Route::post('/pasang', [InstallController::class, 'run'])->middleware('throttle:5,1');

// ---- Publik (tanpa login, divalidasi token) ----
Route::middleware('throttle:publik')->group(function () {
    Route::get('/presensi/{token}', [PublicController::class, 'delegation'])->name('delegation');
    Route::post('/presensi/{token}', [PublicController::class, 'delegationSubmit']);
    Route::get('/wali/{token}', [PublicController::class, 'parent'])->name('parent');
});

// ---- Autentikasi ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/ganti-akun', [AuthController::class, 'showSwitch'])->name('switch');
    Route::post('/ganti-akun', [AuthController::class, 'switch'])->middleware('throttle:10,1');
    Route::get('/password', [AuthController::class, 'showPassword'])->name('password');
    Route::post('/password', [AuthController::class, 'password']);

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/absensi', [AttendanceController::class, 'index'])->name('attendance');
    Route::post('/absensi', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::post('/absensi/hapus', [AttendanceController::class, 'destroy'])->name('attendance.destroy');
    Route::post('/absensi/delegasi', [AttendanceController::class, 'delegate'])->name('attendance.delegate');
    Route::post('/absensi/delegasi/{token}/cabut', [AttendanceController::class, 'revoke'])->name('attendance.revoke');

    Route::get('/nilai', [GradeController::class, 'index'])->name('grades');
    Route::post('/nilai', [GradeController::class, 'store'])->name('grades.store');
    Route::post('/nilai/{activity}/hapus', [GradeController::class, 'destroy'])->name('grades.destroy');

    Route::get('/siswa', [StudentController::class, 'index'])->name('students');
    Route::post('/siswa/{student}/pengesahan', [StudentController::class, 'clearance'])->name('students.clearance');
    Route::post('/siswa/{student}/akses-wali', [StudentController::class, 'parentCreate'])->name('students.parent.create');
    Route::post('/siswa/akses-wali/{token}/cabut', [StudentController::class, 'parentRevoke'])->name('students.parent.revoke');

    Route::middleware('can:bk')->group(function () {
        Route::get('/bk', [BkController::class, 'index'])->name('bk');
        Route::post('/bk', [BkController::class, 'store'])->name('bk.store');
    });

    Route::get('/surat/peringatan/{student}', [LetterController::class, 'warning'])->name('letters.warning');
    Route::get('/surat/panggilan/{student}', [LetterController::class, 'summons'])->name('letters.summons');
    Route::get('/surat/laporan', [LetterController::class, 'report'])->name('letters.report');

    Route::get('/unduh/absensi', [ExportController::class, 'attendance'])->name('export.attendance');
    Route::get('/unduh/nilai', [ExportController::class, 'grades'])->name('export.grades');
    Route::get('/unduh/bk', [ExportController::class, 'bk'])->middleware('can:bk')->name('export.bk');

    // ---- Admin Panel ----
    Route::prefix('admin')->name('admin.')->middleware('can:admin')->group(function () {
        Route::redirect('/', '/admin/guru');
        Route::get('/guru', [Admin\TeacherController::class, 'index'])->name('teachers');
        Route::post('/guru', [Admin\TeacherController::class, 'store'])->name('teachers.store');
        Route::put('/guru/{user}', [Admin\TeacherController::class, 'update'])->name('teachers.update');
        Route::post('/guru/{user}/reset', [Admin\TeacherController::class, 'reset'])->name('teachers.reset');
        Route::post('/guru/{user}/status', [Admin\TeacherController::class, 'toggle'])->name('teachers.toggle');

        Route::get('/siswa', [Admin\StudentController::class, 'index'])->name('students');
        Route::post('/siswa', [Admin\StudentController::class, 'store'])->name('students.store');
        Route::put('/siswa/{student}', [Admin\StudentController::class, 'update'])->name('students.update');
        Route::post('/siswa/impor', [Admin\StudentController::class, 'import'])->name('students.import');

        Route::get('/kelas', [Admin\MasterController::class, 'index'])->name('master');
        Route::post('/kelas', [Admin\MasterController::class, 'storeClass'])->name('classes.store');
        Route::put('/kelas/{class}', [Admin\MasterController::class, 'updateClass'])->name('classes.update');
        Route::post('/mapel', [Admin\MasterController::class, 'storeSubject'])->name('subjects.store');
        Route::put('/mapel/{subject}', [Admin\MasterController::class, 'updateSubject'])->name('subjects.update');

        Route::get('/pasangan', [Admin\PairingController::class, 'index'])->name('pairings');
        Route::post('/pasangan', [Admin\PairingController::class, 'store'])->name('pairings.store');
        Route::delete('/pasangan', [Admin\PairingController::class, 'destroy'])->name('pairings.destroy');

        Route::get('/hardcopy', [Admin\HardcopyController::class, 'index'])->name('hardcopy');
        Route::get('/hardcopy/template', [Admin\HardcopyController::class, 'template'])->name('hardcopy.template');
        Route::post('/hardcopy/pratinjau', [Admin\HardcopyController::class, 'preview'])->name('hardcopy.preview');
        Route::post('/hardcopy/simpan', [Admin\HardcopyController::class, 'commit'])->name('hardcopy.commit');

        Route::get('/log', [Admin\LogController::class, 'index'])->name('logs');

        Route::get('/pengaturan', [Admin\SettingController::class, 'index'])->name('settings');
        Route::put('/pengaturan', [Admin\SettingController::class, 'update'])->name('settings.update');
        Route::post('/backup', [Admin\SettingController::class, 'backup'])->name('backup');
        Route::get('/backup/{file}', [Admin\SettingController::class, 'download'])->name('backup.download');
        Route::get('/ekspor-json', [Admin\SettingController::class, 'json'])->name('json');
    });
});
