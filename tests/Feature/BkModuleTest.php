<?php

namespace Tests\Feature;

use App\Models\BkRecord;
use App\Models\DelegationToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Modul BK terpadu: hak akses per peran, kasus rahasia, prestasi oleh wali, berkas surat, delegasi. */
class BkModuleTest extends TestCase
{
    private function rec(array $o = []): array
    {
        return array_merge(['student_id' => 1, 'tanggal' => $this->daysAgo(1), 'kategori' => 'Sedang', 'judul' => 'Membolos jam ke-3', 'uraian' => 'Kronologi', 'status' => 'Proses'], $o);
    }

    public function test_counselor_records_violation_and_everyone_sees_by_scope(): void
    {
        $this->actingAs($this->bk)->post('/bk/catatan/pelanggaran', $this->rec())->assertSessionHas('success');
        $this->actingAs($this->bk)->post('/bk/catatan/pelanggaran', $this->rec(['student_id' => 5, 'judul' => 'Terlambat kelas dua']))->assertSessionHas('success');
        $this->assertSame(2, BkRecord::count());
        $this->assertSame(1, BkRecord::first()->class_id);

        // Wali kelas 1: hanya kelasnya
        $this->actingAs($this->wali)->get('/bk/catatan/pelanggaran')->assertOk()->assertSee('Membolos jam ke-3')->assertDontSee('Terlambat kelas dua');
        // Kepsek: semua, baca saja
        $this->actingAs($this->kepsek)->get('/bk/catatan/pelanggaran')->assertOk()->assertSee('Terlambat kelas dua')->assertDontSee('Catat Pelanggaran');
        $this->actingAs($this->kepsek)->post('/bk/catatan/pelanggaran', $this->rec())->assertSessionHas('error');
        $this->actingAs($this->wali)->post('/bk/catatan/pelanggaran', $this->rec())->assertSessionHas('error');
        $this->assertSame(2, BkRecord::count());
        // Guru biasa (bukan wali) tidak bisa membuka modul
        $this->actingAs($this->guru)->get('/bk')->assertForbidden();
        $this->actingAs($this->wali)->get('/bk')->assertOk()->assertSee('Bimbingan Konseling');
    }

    public function test_validation_rejects_bad_input(): void
    {
        $this->actingAs($this->bk)->post('/bk/catatan/pelanggaran', $this->rec(['kategori' => 'Parah']))->assertSessionHas('error');
        $this->actingAs($this->bk)->post('/bk/catatan/pelanggaran', $this->rec(['tanggal' => '2999-01-01']))->assertSessionHas('error');
        $this->actingAs($this->bk)->post('/bk/catatan/pelanggaran', $this->rec(['judul' => '']))->assertSessionHas('error');
        $this->actingAs($this->bk)->get('/bk/catatan/tidak-ada')->assertNotFound();
        $this->assertSame(0, BkRecord::count());
    }

    public function test_secret_case_is_masked_for_wali_and_kepsek(): void
    {
        $this->actingAs($this->bk)->post('/bk/catatan/kasus', $this->rec(['kategori' => 'Pribadi', 'judul' => 'Masalah keluarga sensitif', 'rahasia' => 1]))->assertSessionHas('success');
        $this->assertTrue(BkRecord::first()->rahasia);
        $this->actingAs($this->bk)->get('/bk/catatan/kasus')->assertSee('Masalah keluarga sensitif');
        foreach ([$this->wali, $this->kepsek] as $u) {
            $this->actingAs($u)->get('/bk/catatan/kasus')->assertOk()->assertSee('Kasus rahasia')->assertDontSee('Masalah keluarga sensitif');
            $this->actingAs($u)->get('/siswa?id=1')->assertOk()->assertSee('Kasus rahasia')->assertDontSee('Masalah keluarga sensitif');
        }
    }

    public function test_wali_can_record_achievement_for_own_class_only(): void
    {
        $p = ['student_id' => 1, 'tanggal' => $this->daysAgo(2), 'kategori' => 'Kabupaten/Kota', 'judul' => 'Lomba Poster', 'extra' => ['peringkat' => 'Juara 2']];
        $this->actingAs($this->wali)->post('/bk/catatan/prestasi', $p)->assertSessionHas('success');
        $this->actingAs($this->wali)->post('/bk/catatan/prestasi', ['student_id' => 5] + $p)->assertSessionHas('error');
        $r = BkRecord::sole();
        $this->assertSame('Juara 2', $r->x('peringkat'));
        $this->assertNull($r->status);
        // Wali boleh mengubah catatannya sendiri, tapi bukan milik BK
        $this->actingAs($this->wali)->put("/bk/catatan/prestasi/{$r->id}", ['tanggal' => $r->tanggal, 'kategori' => 'Provinsi', 'judul' => 'Lomba Poster'])->assertSessionHas('success');
        $this->assertSame('Provinsi', $r->fresh()->kategori);
        $this->actingAs($this->bk)->post('/bk/catatan/prestasi', ['student_id' => 2] + $p);
        $mine = BkRecord::where('student_id', 2)->sole();
        $this->actingAs($this->wali)->delete("/bk/catatan/prestasi/{$mine->id}")->assertSessionHas('error');
        $this->actingAs($this->bk)->delete("/bk/catatan/prestasi/{$mine->id}")->assertSessionHas('success');
    }

    public function test_letter_scan_upload_and_access(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('surat.jpg');
        $this->actingAs($this->bk)->post('/bk/catatan/surat', ['student_id' => 1, 'tanggal' => $this->daysAgo(1), 'kategori' => 'Surat Panggilan Orang Tua', 'judul' => 'Panggilan ortu', 'berkas' => $file])->assertSessionHas('success');
        $r = BkRecord::sole();
        Storage::disk('local')->assertExists($r->berkas_path);
        $this->actingAs($this->wali)->get("/bk/catatan/surat/{$r->id}/berkas")->assertOk();
        $this->actingAs($this->kepsek)->get("/bk/catatan/surat/{$r->id}/berkas")->assertOk();
        $this->actingAs($this->guru)->get("/bk/catatan/surat/{$r->id}/berkas")->assertForbidden();
        $bad = UploadedFile::fake()->create('virus.exe', 10);
        $this->actingAs($this->bk)->post('/bk/catatan/surat', ['student_id' => 1, 'tanggal' => $this->daysAgo(1), 'kategori' => 'Lainnya', 'judul' => 'x', 'berkas' => $bad])->assertSessionHasErrors('berkas');
        $this->actingAs($this->bk)->delete("/bk/catatan/surat/{$r->id}")->assertSessionHas('success');
        Storage::disk('local')->assertMissing($r->berkas_path);
    }

    public function test_bk_can_create_and_revoke_delegation_for_any_class(): void
    {
        $this->actingAs($this->bk)->post('/bk/delegasi', ['class' => 2, 'hours' => 3])->assertSessionHas('new_token');
        $t = DelegationToken::where('class_id', 2)->sole();
        $this->actingAs($this->bk)->get('/bk/presensi?class=2')->assertOk()->assertSee(substr($t->token, 0, 12));
        $this->actingAs($this->bk)->post("/bk/delegasi/{$t->token}/cabut")->assertSessionHas('success');
        $this->assertNotSame('aktif', $t->fresh()->status);
        $this->actingAs($this->wali)->post('/bk/delegasi', ['class' => 2])->assertForbidden();
    }
}
