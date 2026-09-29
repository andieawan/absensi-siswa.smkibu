<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\DelegationToken;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Regresi untuk temuan audit 30 Sep 2026. */
class AuditFixTest extends TestCase
{
    private function grade(array $over = [])
    {
        return $this->actingAs($this->wali)->post('/nilai', array_merge(['class' => 1, 'subject' => 1, 'nama' => 'UH', 'tanggal' => Dates::today(), 'tipe' => 'angka', 'nilai' => [1 => '50', 2 => '60']], $over));
    }

    public function test_dashboard_sekolah_tetap_benar_dengan_agregasi_database(): void
    {
        foreach ([[1, 'H'], [2, 'A'], [3, 'S'], [5, 'I']] as [$s, $st]) {
            $this->mark($s, $this->daysAgo(1), $st);
        }
        $this->mark(2, $this->daysAgo(2), 'A');
        $this->mark(1, $this->daysAgo(1), 'A', 2); // absen mapel: tidak dihitung di agregat sekolah
        $r = $this->actingAs($this->kepsek)->get('/?v=sekolah&class=0')->assertOk();
        $r->assertViewHas('counts', fn ($c) => [$c['total'], $c['hadir'], $c['alpa'], $c['izin'], $c['sakit']] === [5, 1, 2, 1, 1]);
        $r->assertViewHas('trend', fn ($t) => count($t) === 2 && $t[1]['total'] === 4 && $t[1]['pct'] === 25);
        $r->assertViewHas('attention', fn ($a) => $a[0]['student_id'] === 2 && $a[0]['category'] === 'alpa_tinggi');
    }

    public function test_nilai_terkunci_7_hari_sejak_diinput(): void
    {
        $this->grade(['tanggal' => $this->daysAgo(30)])->assertSessionHas('success'); // input terlambat tetap boleh
        $act = GradeActivity::firstOrFail();
        $act->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400)]);
        $this->grade(['act' => $act->id, 'nilai' => [1 => '99']])->assertSessionHas('error', fn ($m) => str_contains($m, 'dikunci'));
        $this->grade(['act' => $act->id, 'tanggal' => Dates::today(), 'nilai' => [1 => '99']])->assertSessionHas('error');
        $this->assertSame('50', GradeValue::where(['activity_id' => $act->id, 'student_id' => 1])->value('nilai'));
        $this->post("/nilai/{$act->id}/hapus")->assertSessionHas('error');
        $this->get("/nilai?class=1&subject=1&act={$act->id}")->assertDontSee('Mengedit kegiatan');
        $this->actingAs($this->admin)->post('/nilai', ['class' => 1, 'subject' => 1, 'act' => $act->id, 'nama' => 'UH', 'tanggal' => Dates::today(), 'tipe' => 'angka', 'nilai' => [1 => '99']])->assertSessionHas('success');
    }

    public function test_tanggal_nilai_masa_depan_ditolak(): void
    {
        $this->grade(['tanggal' => gmdate('Y-m-d', strtotime(Dates::today().' UTC') + 86400)])->assertSessionHas('error', fn ($m) => str_contains($m, 'masa depan'));
    }

    public function test_edit_nilai_tidak_menghapus_nilai_siswa_pindah(): void
    {
        $this->grade();
        Student::whereKey(2)->update(['status' => 'pindah']);
        $act = GradeActivity::firstOrFail();
        $this->grade(['act' => $act->id, 'nilai' => [1 => '70']])->assertSessionHas('success');
        $this->assertSame('60', GradeValue::where(['activity_id' => $act->id, 'student_id' => 2])->value('nilai'));
        $this->assertSame('70', GradeValue::where(['activity_id' => $act->id, 'student_id' => 1])->value('nilai'));
    }

    public function test_impor_besar_tidak_menghapus_riwayat_log(): void
    {
        $this->actingAs($this->admin)->put('/admin/pengaturan', ['school_name' => 'X', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8]);
        $csv = "NIS,Nama Siswa,JK,Kelas\n";
        for ($i = 0; $i < 520; $i++) {
            $csv .= "Z-$i,Nama $i,L,X RPL 1\n";
        }
        $this->post('/admin/siswa/impor', ['file' => UploadedFile::fake()->createWithContent('s.csv', $csv)])->assertSessionHas('success');
        $this->assertTrue(AuditLog::where('action', 'Ubah Pengaturan Sekolah')->exists());
        $this->assertTrue(AuditLog::where('action', 'Impor Siswa')->where('details', 'like', '520 siswa%')->exists());
    }

    public function test_absensi_besok_ditolak_kapan_pun(): void
    {
        $besok = gmdate('Y-m-d', strtotime(Dates::today().' UTC') + 86400);
        $this->assertTrue(Dates::isFuture($besok));
        $this->assertFalse(Dates::isFuture(Dates::today()));
        $this->actingAs($this->wali)->post('/absensi', ['mode' => 'wali', 'class' => 1, 'date' => $besok, 'status' => $this->statuses()])->assertSessionHas('error', fn ($m) => str_contains($m, 'masa depan'));
        $this->actingAs($this->wali)->post('/absensi/delegasi', ['mode' => 'wali', 'class' => 1]);
        $t = DelegationToken::firstOrFail()->token;
        $this->post("/presensi/$t", ['date' => $besok, 'status' => $this->statuses()])->assertSessionHas('error');
        $this->assertSame(0, Attendance::count());
    }

    public function test_delegasi_bisa_dicabut(): void
    {
        $this->actingAs($this->wali)->post('/absensi/delegasi', ['mode' => 'wali', 'class' => 1]);
        $t = DelegationToken::firstOrFail()->token;
        $this->get('/absensi?mode=wali&class=1&tab=riwayat')->assertSee('Tautan yang masih aktif');
        $this->actingAs($this->guru)->post("/absensi/delegasi/$t/cabut", ['mode' => 'wali', 'class' => 1])->assertSessionHas('error');
        $this->actingAs($this->wali)->post("/absensi/delegasi/$t/cabut", ['mode' => 'wali', 'class' => 1])->assertSessionHas('success');
        auth()->logout();
        $this->get("/presensi/$t")->assertStatus(410)->assertSee('tidak aktif');
    }

    public function test_satu_kelas_satu_wali(): void
    {
        $this->actingAs($this->admin)->post('/admin/guru', ['nama' => 'Wali Dua', 'username' => 'wali.dua', 'password' => 'Sandi12345', 'roles' => ['guru'], 'kelas_wali_id' => 1])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'sudah punya wali kelas'));
        $this->assertFalse(User::where('username', 'wali.dua')->exists());
        $this->put("/admin/guru/{$this->guru->id}", ['nama' => 'Guru', 'roles' => ['guru'], 'kelas_wali_id' => 1])->assertSessionHas('error');
        $this->put("/admin/guru/{$this->wali->id}", ['nama' => 'Wali', 'roles' => ['guru'], 'kelas_wali_id' => 1])->assertSessionHas('success'); // diri sendiri boleh
        $this->put("/admin/guru/{$this->guru->id}", ['nama' => 'Guru', 'roles' => ['guru'], 'kelas_wali_id' => 2])->assertSessionHas('success');
    }

    public function test_catatan_absensi_maksimal_200_karakter(): void
    {
        $this->actingAs($this->wali)->post('/absensi', ['mode' => 'wali', 'class' => 1, 'date' => Dates::today(), 'status' => [1 => 'I'], 'notes' => [1 => str_repeat('x', 201)]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, '200 karakter'));
        $this->assertSame(0, Attendance::count());
    }
}
