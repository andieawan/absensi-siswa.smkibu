<?php

namespace Tests;

use App\Models\Attendance;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Dates;
use App\Support\Passwords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $wali;    // wali kelas 1, mengajar mapel 1 di kelas 1
    protected User $guru;    // guru mapel 2, pasangan di kelas 1
    protected User $kepsek;
    protected User $bk;

    protected function setUp(): void
    {
        parent::setUp();
        SchoolSetting::forget();
        config(['absensi.backup_dir' => storage_path('framework/testing/backups')]);

        SchoolClass::create(['id' => 1, 'name' => 'X RPL 1', 'jurusan' => 'RPL']);
        SchoolClass::create(['id' => 2, 'name' => 'X TKJ 1', 'jurusan' => 'TKJ']);
        foreach ([1 => 'Bahasa Indonesia', 2 => 'Matematika', 5 => 'Bimbingan Konseling'] as $id => $n) {
            Subject::create(['id' => $id, 'name' => $n]);
        }
        foreach (range(1, 6) as $i) {
            Student::create(['id' => $i, 'nis' => "N-$i", 'nama' => "Siswa $i", 'jk' => $i % 2 ? 'L' : 'P', 'class_id' => $i <= 4 ? 1 : 2, 'status' => 'aktif']);
        }
        $mk = fn (string $u, array $roles, array $extra = []) => User::create(array_merge([
            'username' => $u, 'nama' => ucfirst($u), 'password_hash' => Passwords::make('password123'), 'roles' => $roles, 'is_active' => true,
        ], $extra));
        $this->admin = $mk('admin', ['superadmin', 'admin']);
        $this->wali = $mk('wali', ['guru'], ['kelas_wali_id' => 1, 'subjects' => [1], 'classes' => [1]]);
        $this->guru = $mk('guru', ['guru'], ['subjects' => [2]]);
        $this->kepsek = $mk('kepsek', ['kepsek']);
        $this->bk = $mk('bk', ['bk']);
        Pairing::create(['user_id' => $this->guru->id, 'subject_id' => 2, 'class_id' => 1]);
    }

    /** Ganti pengguna di tengah tes: buang sidik password sesi lama (middleware auth.session). */
    public function be(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        if ($this->app->bound('session.store')) {
            $this->app['session.store']->forget('password_hash_web');
        }

        return parent::be($user, $guard);
    }

    protected function daysAgo(int $n): string
    {
        return gmdate('Y-m-d', strtotime(Dates::today().' UTC') - $n * 86400);
    }

    /** Payload status[] untuk siswa kelas 1 (id 1..4). */
    protected function statuses(array $map = []): array
    {
        $out = [];
        foreach ([1, 2, 3, 4] as $id) {
            $out[$id] = $map[$id] ?? 'H';
        }

        return $out;
    }

    protected function mark(int $studentId, string $date, string $status, ?int $subject = null): void
    {
        $s = Student::find($studentId);
        Attendance::create(['student_id' => $studentId, 'class_id' => $s->class_id, 'subject_id' => $subject, 'tanggal' => $date, 'status' => $status,
            'recorded_via' => 'wali', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    }
}
