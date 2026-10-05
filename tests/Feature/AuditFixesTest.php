<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Perbaikan hasil audit kode (Okt 2026). */
class AuditFixesTest extends TestCase
{
    private function absenSiswa5(): void
    {
        foreach (range(1, 4) as $i) {
            Attendance::create(['id' => 900 + $i, 'student_id' => 5, 'class_id' => 2, 'subject_id' => 1, 'tanggal' => date('Y-m-d', strtotime("-$i day")), 'status' => 'A',
                'recorded_by' => $this->admin->id, 'created_at' => 'x', 'updated_at' => 'x', 'created_at_millis' => 1]);
        }
    }

    public function test_dashboard_does_not_leak_other_class_to_guru(): void
    {
        $this->absenSiswa5();
        $this->actingAs($this->guru)->get('/?v=mapel&class=2&subject=1')->assertOk()->assertDontSee('Siswa 5');
        $this->actingAs($this->admin)->get('/?v=mapel&class=2&subject=1')->assertOk()->assertSee('Siswa 5');
    }

    public function test_dashboard_class_filter_only_lists_own_classes(): void
    {
        $html = $this->actingAs($this->guru)->get('/?v=mapel')->assertOk()->getContent();
        preg_match('/<select name="class".*?<\/select>/s', $html, $m);
        $this->assertStringNotContainsString('value="2"', $m[0]);
    }

    public function test_clearance_requires_wali_bk_kepsek_or_admin(): void
    {
        $this->actingAs($this->guru)->post('/siswa/1/pengesahan')->assertSessionHas('error', fn ($m) => str_contains($m, 'hanya oleh Wali Kelas'));
        $this->actingAs($this->wali)->post('/siswa/5/pengesahan')->assertSessionHas('error', fn ($m) => str_contains($m, 'hanya oleh Wali Kelas'));
        // wali kelas 1 untuk siswanya sendiri: lolos cek peran (hasil ditentukan kehadiran, bukan peran)
        $this->actingAs($this->wali)->post('/siswa/1/pengesahan')->assertSessionHas('success', fn ($m) => str_contains($m, 'DISETUJUI'));
    }

    public function test_upload_rejects_non_image_non_pdf(): void
    {
        $f = UploadedFile::fake()->create('x.php', 10, 'text/x-php');
        $this->actingAs($this->admin)->post('/tu/surat/masuk', ['berkas' => $f, 'perihal' => 'a', 'pihak' => 'x', 'tanggal' => date('Y-m-d')])
            ->assertSessionHasErrors('berkas');
    }

    public function test_csp_header_is_sent(): void
    {
        $this->get('/login')->assertHeader('Content-Security-Policy');
    }
}
