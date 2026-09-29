<?php

namespace Tests\Feature;

use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Support\Dates;
use Tests\TestCase;

class GradeTest extends TestCase
{
    private function save(array $over = [], $user = null)
    {
        return $this->actingAs($user ?? $this->wali)->post('/nilai', array_merge([
            'class' => 1, 'subject' => 1, 'nama' => 'UH 1', 'tanggal' => Dates::today(), 'tipe' => 'angka',
            'nilai' => [1 => '55', 2 => '80', 3 => '', 4 => '100'],
        ], $over));
    }

    public function test_simpan_edit_dan_rekap(): void
    {
        $this->save()->assertSessionHas('success');
        $act = GradeActivity::firstOrFail();
        $this->assertNull(GradeValue::where(['activity_id' => $act->id, 'student_id' => 3])->value('nilai')); // kosong = belum mengumpulkan
        $this->get("/nilai?class=1&subject=1&act={$act->id}")->assertSee('value="55"', false)->assertSee('Mengedit kegiatan');
        $this->save(['act' => $act->id, 'nama' => 'UH 1 revisi', 'nilai' => [1 => '90', 2 => '80', 3 => '70', 4 => '100']]);
        $this->assertSame(1, GradeActivity::count());
        $this->assertSame('90', GradeValue::where(['activity_id' => $act->id, 'student_id' => 1])->value('nilai'));
        $this->get('/nilai?class=1&subject=1&tab=rekap')->assertSee('UH 1 revisi')->assertSee('90.0');
        $this->get('/nilai?class=1&subject=1&tab=aktivitas')->assertSee('85.0');
        // mengosongkan nilai saat edit (≤ 7 hari) = menghapus nilai siswa itu
        $this->save(['act' => $act->id, 'nama' => 'UH 1 revisi', 'nilai' => [1 => '90', 2 => '', 3 => '70', 4 => '100']]);
        $this->assertNull(GradeValue::where(['activity_id' => $act->id, 'student_id' => 2])->value('nilai'));
        $res = $this->get('/unduh/nilai?class=1&subject=1');
        $res->assertOk();
        $this->assertStringStartsWith('PK', $res->getContent());
    }

    public function test_validasi_skala(): void
    {
        $this->save(['nilai' => [1 => '150']])->assertSessionHas('error', fn ($m) => str_contains($m, '0–100'));
        $this->save(['tipe' => 'huruf', 'nilai' => [1 => 'F']])->assertSessionHas('error', fn ($m) => str_contains($m, 'A–E'));
        $this->save(['tipe' => 'huruf', 'nilai' => [1 => 'b', 2 => 'A']])->assertSessionHas('success');
        $this->assertSame('B', GradeValue::where('student_id', 1)->value('nilai'));
        $this->save(['nilai' => [5 => '80']])->assertSessionHas('error', fn ($m) => str_contains($m, 'bukan anggota kelas'));
    }

    public function test_otorisasi_dan_kunci_hapus(): void
    {
        $this->save([], $this->guru)->assertSessionHas('error', fn ($m) => str_contains($m, 'Otorisasi'));
        $this->assertSame(0, GradeActivity::count());

        $this->save();
        $act = GradeActivity::firstOrFail();
        $this->post("/nilai/{$act->id}/hapus")->assertSessionHas('success');
        $this->assertSame(0, GradeValue::count());

        $this->save(['tanggal' => $this->daysAgo(12)]);
        $old = GradeActivity::firstOrFail();
        $old->update(['created_at' => gmdate('Y-m-d H:i:s', time() - 8 * 86400)]); // diinput 8 hari lalu
        $this->post("/nilai/{$old->id}/hapus")->assertSessionHas('error', fn ($m) => str_contains($m, '7 hari'));
        $this->actingAs($this->admin)->post("/nilai/{$old->id}/hapus")->assertSessionHas('success');
    }
}
