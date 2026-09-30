<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Berkas versi baru sudah diunggah tetapi "Perbarui Database" belum diklik: absensi harus tetap jalan. */
class PreMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Perubahan struktur tabel hanya bisa dibatalkan otomatis di SQLite.');
        }
        Schema::dropIfExists('bk_records');
        foreach (['tu_surat', 'mutasi_siswa', 'staff_attendance'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('students', fn ($t) => $t->dropColumn(['nama_ortu', 'telp_ortu']));
    }

    public function test_core_pages_still_work_before_database_update(): void
    {
        $this->mark(1, \App\Support\Dates::today(), 'A');
        foreach (['/', '/absensi?mode=wali', '/nilai', '/siswa?id=1', '/rekap'] as $url) {
            $this->actingAs($this->wali)->get($url)->assertOk();
        }
        $this->actingAs($this->wali)->post('/absensi', ['mode' => 'wali', 'class' => 1, 'date' => \App\Support\Dates::today(), 'status' => $this->statuses([2 => 'S'])])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();
        $this->assertSame('S', \App\Models\Attendance::where('student_id', 2)->value('status'));
        $this->actingAs($this->kepsek)->get('/pantau')->assertOk();
        $this->actingAs($this->bk)->get('/bk/presensi?class=1')->assertOk();
        $this->actingAs($this->bk)->get('/bk')->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->admin)->get('/admin/siswa')->assertOk();
        $this->actingAs($this->admin)->put('/admin/siswa/1', ['nama' => 'Siswa Satu', 'jk' => 'L', 'class_id' => 1, 'status' => 'aktif', 'telp_ortu' => '0812345678'])->assertSessionHas('success');
    }

    public function test_tu_pages_show_hint_and_student_data_still_works_before_database_update(): void
    {
        $tu = \App\Models\User::create(['username' => 'tu', 'nama' => 'TU', 'password_hash' => \App\Support\Passwords::make('password123'), 'roles' => ['tu'], 'is_active' => true]);
        foreach (['/tu', '/tu/mutasi', '/tu/surat/keluar', '/tu/absen-guru', '/tu/cetak'] as $url) {
            $this->actingAs($tu)->get($url)->assertRedirect()->assertSessionHas('error');
        }
        $this->actingAs($tu)->get('/tu/siswa')->assertOk();
        $this->actingAs($tu)->post('/tu/siswa', ['nis' => 'Z-1', 'nama' => 'Baru', 'jk' => 'L', 'class_id' => 1, 'status' => 'aktif'])->assertSessionHas('success');
    }
}
