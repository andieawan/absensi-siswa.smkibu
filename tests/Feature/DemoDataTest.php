<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BkRecord;
use App\Models\GradeValue;
use App\Models\SchoolClass;
use App\Models\StaffAttendance;
use App\Models\Student;
use App\Models\TuSurat;
use App\Models\User;
use Tests\TestCase;

/** Tombol "Isi Data Contoh" (Admin → Pengaturan) untuk mencoba aplikasi tanpa terminal. */
class DemoDataTest extends TestCase
{
    private function emptySchool(): void
    {
        \Illuminate\Support\Facades\DB::table('teacher_subject_class_pairing')->delete();
        User::whereIn('id', [$this->wali->id, $this->guru->id, $this->kepsek->id, $this->bk->id])->delete();
        Student::query()->delete();
        SchoolClass::query()->delete();
        \App\Models\Subject::query()->delete();
    }

    public function test_demo_data_fills_everything_once_and_lists_logins(): void
    {
        $this->emptySchool();
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('Isi Data Contoh');
        $r = $this->actingAs($this->admin)->post('/admin/data-contoh')->assertSessionHas('success')->assertSessionHas('demo_passwords');
        $pw = session('demo_passwords');
        $this->assertSame(['ibu.siti', 'pak.hendra', 'bu.ratna', 'bu.maya', 'bu.tata'], array_keys($pw));
        $this->assertSame([4, 48], [SchoolClass::count(), Student::count()]);
        $this->assertGreaterThan(100, Attendance::count());
        $this->assertGreaterThan(0, GradeValue::count());
        $this->assertSame(5, BkRecord::count());
        $this->assertSame(4, TuSurat::count());
        $this->assertGreaterThan(20, StaffAttendance::count());
        $this->assertNotNull(Student::find(1)->telp_ortu);

        // Tidak bisa dua kali; tombol hilang
        $this->actingAs($this->admin)->post('/admin/data-contoh')->assertSessionHas('error');
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertDontSee('Isi Data Contoh');
        $this->assertSame(48, Student::count());

        // Akun contoh bisa login & halaman utama tampil
        foreach (['ibu.siti' => '/', 'bu.ratna' => '/pantau', 'bu.maya' => '/bk', 'bu.tata' => '/tu'] as $u => $url) {
            $this->post('/logout');
            $this->actingAs(User::where('username', $u)->first())->get($url)->assertOk();
        }
        $this->assertNotEmpty($pw['bu.tata']);
    }

    public function test_demo_requires_admin_and_empty_app(): void
    {
        $this->actingAs($this->wali)->post('/admin/data-contoh')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/data-contoh')->assertSessionHas('error');   // sudah ada kelas/siswa
    }
}
