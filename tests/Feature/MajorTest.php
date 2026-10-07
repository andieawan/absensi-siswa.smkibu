<?php

namespace Tests\Feature;

use App\Models\Jurusan;
use App\Models\SchoolClass;
use Tests\TestCase;

class MajorTest extends TestCase
{
    public function test_admin_only(): void
    {
        $this->actingAs($this->wali)->get('/admin/jurusan')->assertForbidden();
        $this->actingAs($this->wali)->post('/admin/jurusan', ['kode' => 'RPL', 'nama' => 'x'])->assertForbidden();
    }

    public function test_crud_and_rename_updates_classes(): void
    {
        $a = $this->actingAs($this->admin);
        $a->post('/admin/jurusan', ['kode' => 'rpl', 'nama' => 'Rekayasa Perangkat Lunak'])->assertSessionHas('success');
        $this->assertSame('RPL', Jurusan::firstOrFail()->kode);
        $a->post('/admin/jurusan', ['kode' => 'RPL', 'nama' => 'dobel'])->assertSessionHas('error');
        $a->post('/admin/jurusan', ['kode' => 'R@PL', 'nama' => 'x'])->assertSessionHasErrors('kode');
        $this->assertSame(1, Jurusan::count());

        SchoolClass::find(1)->update(['jurusan' => 'rpl']);
        $m = Jurusan::firstOrFail();
        $a->get('/admin/jurusan')->assertOk()->assertSee('Rekayasa Perangkat Lunak')->assertSee('X RPL 1');
        $a->put("/admin/jurusan/{$m->id}", ['kode' => 'TKJ2', 'nama' => 'Baru'])->assertSessionHas('success');
        $this->assertSame('TKJ2', SchoolClass::find(1)->jurusan);

        $a->delete("/admin/jurusan/{$m->id}")->assertSessionHas('error'); // masih dipakai kelas 1
        $this->assertSame(1, Jurusan::count());
        SchoolClass::find(1)->update(['jurusan' => null]);
        $a->delete("/admin/jurusan/{$m->id}")->assertSessionHas('success');
        $this->assertSame(0, Jurusan::count());
    }

    public function test_sync_registers_unknown_codes(): void
    {
        SchoolClass::find(1)->update(['jurusan' => 'DKV']);
        $this->actingAs($this->admin)->get('/admin/jurusan')->assertOk()->assertSee('belum terdaftar');
        $this->actingAs($this->admin)->post('/admin/jurusan/sinkron')->assertSessionHas('success');
        $this->assertTrue(Jurusan::where('kode', 'DKV')->exists());
        $this->actingAs($this->admin)->post('/admin/jurusan/sinkron');
        $this->assertSame(1, Jurusan::where('kode', 'DKV')->count());
    }

    public function test_bulk_create_classes_skips_existing(): void
    {
        $m = Jurusan::create(['kode' => 'RPL', 'nama' => 'Rekayasa Perangkat Lunak']);
        $before = SchoolClass::count();
        $this->actingAs($this->admin)->post('/admin/jurusan/kelas', ['jurusan_id' => $m->id, 'tingkat' => 'X', 'jumlah' => 3])->assertSessionHas('success');
        // fixture sudah punya "X RPL 1" → hanya 2 baru
        $this->assertSame($before + 2, SchoolClass::count());
        $this->assertTrue(SchoolClass::where('name', 'X RPL 3')->where('jurusan', 'RPL')->exists());
        $this->actingAs($this->admin)->post('/admin/jurusan/kelas', ['jurusan_id' => $m->id, 'tingkat' => 'XIII', 'jumlah' => 1])->assertSessionHasErrors('tingkat');
        $this->actingAs($this->admin)->post('/admin/jurusan/kelas', ['jurusan_id' => $m->id, 'tingkat' => 'X', 'jumlah' => 99])->assertSessionHasErrors('jumlah');
    }

    public function test_master_page_uses_dropdown_when_ready(): void
    {
        Jurusan::create(['kode' => 'RPL', 'nama' => 'Rekayasa Perangkat Lunak']);
        SchoolClass::find(2)->update(['jurusan' => 'ANEH']);
        $this->actingAs($this->admin)->get('/admin/kelas')->assertOk()->assertSee('RPL — Rekayasa Perangkat Lunak')->assertSee('ANEH (belum terdaftar)', false);
    }
}
