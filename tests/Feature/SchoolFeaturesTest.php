<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Support\TahunAjaran;
use App\Support\WhatsApp;
use Tests\TestCase;

/** Pantau absen, rekap rapor, kenaikan kelas, tahun ajaran, WhatsApp gratis, kontak orang tua. */
class SchoolFeaturesTest extends TestCase
{
    public function test_monitor_lists_classes_that_have_not_filled(): void
    {
        $today = \App\Support\Dates::today();
        $this->mark(1, $today, 'H');
        $this->actingAs($this->kepsek)->get('/pantau')->assertOk()->assertSee('X RPL 1')->assertSee('X TKJ 1')->assertSee('✓ Sudah', false);
        $this->actingAs($this->kepsek)->get('/pantau?f=belum')->assertOk()->assertSee('X TKJ 1')->assertDontSee('X RPL 1');
        $this->actingAs($this->wali)->get('/pantau')->assertForbidden();
        $this->actingAs($this->kepsek)->get('/')->assertOk()->assertSee('1/2 kelas');
    }

    public function test_semester_recap_counts_daily_only_within_range(): void
    {
        SchoolSetting::put(['tahun_ajaran' => '2025/2026', 'semester' => 'Genap']);
        $this->mark(1, '2026-02-02', 'S');
        $this->mark(1, '2026-02-03', 'A');
        $this->mark(1, '2026-02-04', 'I');
        $this->mark(1, '2026-02-05', 'H');
        $this->mark(1, '2026-02-06', 'A', 1);   // mapel: tidak dihitung
        $this->mark(1, '2025-12-01', 'A');      // semester Ganjil: di luar rentang

        $r = $this->actingAs($this->wali)->get('/rekap?semester=Genap')->assertOk()->assertSee('Siswa 1');
        $rows = $r->viewData('rows');
        $row = collect($rows)->firstWhere('student.id', 1);
        $this->assertSame([1, 1, 1, 1, 4], [$row['S'], $row['I'], $row['A'], $row['H'], $row['total']]);
        $this->assertSame(['2026-01-01', '2026-06-30'], [$r->viewData('from'), $r->viewData('to')]);

        $this->actingAs($this->wali)->get('/rekap?class=2')->assertForbidden();
        $this->actingAs($this->guru)->get('/rekap')->assertForbidden();
        $this->actingAs($this->kepsek)->get('/rekap?class=2')->assertOk();
        $x = $this->actingAs($this->wali)->get('/rekap/unduh?semester=Genap');
        $x->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
    }

    public function test_tahun_ajaran_ranges(): void
    {
        $this->assertSame([2026, 2027], TahunAjaran::years('2026/2027'));
        $this->assertSame(['2026-07-01', '2026-12-31'], TahunAjaran::semesterRange('Ganjil', '2026/2027'));
        $this->assertSame(['2027-01-01', '2027-06-30'], TahunAjaran::semesterRange('Genap', '2026/2027'));
    }

    public function test_promotion_moves_selected_students_and_keeps_others(): void
    {
        $c3 = SchoolClass::create(['id' => 3, 'name' => 'XI RPL 1']);
        $this->actingAs($this->admin)->get('/admin/kenaikan?dari=1')->assertOk()->assertSee('Siswa 1');
        $this->actingAs($this->admin)->post('/admin/kenaikan', ['dari' => 1, 'ke' => (string) $c3->id, 'siswa' => [1, 2, 3]])->assertSessionHas('success');
        $this->assertSame([3, 3, 3, 1], Student::whereIn('id', [1, 2, 3, 4])->orderBy('id')->pluck('class_id')->all());
    }

    public function test_promotion_to_occupied_class_needs_confirmation(): void
    {
        $this->actingAs($this->admin)->post('/admin/kenaikan', ['dari' => 1, 'ke' => '2', 'siswa' => [1]])->assertSessionHas('error');
        $this->assertSame(1, Student::find(1)->class_id);
        $this->actingAs($this->admin)->post('/admin/kenaikan', ['dari' => 1, 'ke' => '2', 'siswa' => [1], 'paham' => 1])->assertSessionHas('success');
        $this->assertSame(2, Student::find(1)->class_id);
    }

    public function test_graduation_and_admin_only(): void
    {
        $this->actingAs($this->wali)->post('/admin/kenaikan', ['dari' => 2, 'ke' => 'lulus', 'siswa' => [5, 6]])->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/kenaikan', ['dari' => 2, 'ke' => 'lulus', 'siswa' => [5, 6]])->assertSessionHas('success');
        $this->assertSame(['lulus', 'lulus'], Student::whereIn('id', [5, 6])->pluck('status')->all());
        $this->assertSame(0, Student::active()->where('class_id', 2)->count());
    }

    public function test_new_school_year(): void
    {
        $this->actingAs($this->admin)->post('/admin/tahun-ajaran', ['tahun_ajaran' => '2027/2028'])->assertSessionHas('success');
        SchoolSetting::forget();
        $this->assertSame('2027/2028', SchoolSetting::current()->tahun_ajaran);
        $this->assertSame('Ganjil', SchoolSetting::current()->semester);
        $this->assertSame('2027/2028', SchoolClass::find(1)->tahun_ajaran);
        $this->actingAs($this->admin)->post('/admin/tahun-ajaran', ['tahun_ajaran' => '2027-2029'])->assertSessionHas('error');
    }

    public function test_whatsapp_number_normalization_and_link(): void
    {
        $this->assertSame('6281234567890', WhatsApp::normalize('0812-3456-7890'));
        $this->assertSame('6281234567890', WhatsApp::normalize('+62 812 3456 7890'));
        $this->assertSame('6281234567890', WhatsApp::normalize('81234567890'));
        $this->assertNull(WhatsApp::normalize('123'));
        $this->assertNull(WhatsApp::normalize(''));
        $this->assertSame('081234567890', WhatsApp::display('6281234567890'));
        $this->assertSame('https://wa.me/6281234567890?text=Halo%20Bu', WhatsApp::link('081234567890', 'Halo Bu'));
        $this->assertNull(WhatsApp::link(null, 'x'));
    }

    public function test_parent_phone_saved_validated_and_used_for_whatsapp(): void
    {
        $this->actingAs($this->admin)->put('/admin/siswa/1', ['nama' => 'Siswa 1', 'jk' => 'L', 'class_id' => 1, 'status' => 'aktif', 'nama_ortu' => 'Bu Aminah', 'telp_ortu' => '0812 3456 7890'])->assertSessionHas('success');
        $this->assertSame('6281234567890', Student::find(1)->telp_ortu);
        $this->actingAs($this->admin)->put('/admin/siswa/1', ['nama' => 'Siswa 1', 'jk' => 'L', 'class_id' => 1, 'status' => 'aktif', 'telp_ortu' => 'abc'])->assertSessionHas('error');
        $this->assertSame('6281234567890', Student::find(1)->telp_ortu);

        $today = \App\Support\Dates::today();
        $this->mark(1, $today, 'A');
        $this->actingAs($this->wali)->get('/absensi?mode=wali')->assertOk()->assertSee('Kabari Orang Tua')->assertSee('https://wa.me/6281234567890?text=', false);
        $this->actingAs($this->wali)->get('/siswa?id=1')->assertOk()->assertSee('Bu Aminah')->assertSee('Kirim ringkasan via WhatsApp');
    }

    public function test_import_reads_optional_parent_columns(): void
    {
        [$ok, $skip] = \App\Services\AdminService::importStudents($this->admin, ['nis', 'nama siswa', 'jk', 'kelas', 'nama ortu', 'no hp ortu'], [
            ['N-100', 'Budi', 'L', 'X RPL 1', 'Pak Slamet', '085711112222'],
            ['N-101', 'Sari', 'P', 'X RPL 1', '', 'salah'],
        ]);
        $this->assertSame(1, $ok);
        $this->assertCount(1, $skip);
        $this->assertSame(['Pak Slamet', '6285711112222'], [Student::where('nis', 'N-100')->value('nama_ortu'), Student::where('nis', 'N-100')->value('telp_ortu')]);
    }

    public function test_admin_can_apply_pending_database_updates_without_ssh(): void
    {
        $this->actingAs($this->admin)->post('/admin/perbarui-database')->assertSessionHas('success', 'Database sudah versi terbaru.');
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_09_30_000100_add_bk_and_parent_contact')->delete();
        $this->actingAs($this->admin)->get('/admin/guru')->assertSee('pembaruan database');
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertSee('Perbarui Database Sekarang');
        $this->actingAs($this->wali)->post('/admin/perbarui-database')->assertForbidden();
        $this->actingAs($this->admin)->post('/admin/perbarui-database')->assertSessionHas('success');
        $this->assertSame([], \App\Support\DbUpdate::pending());
    }
}
