<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Riwayat Siswa: guru mapel hanya melihat kelas & siswa yang diajarnya. */
class StudentHistoryScopeTest extends TestCase
{
    public function test_guru_mapel_only_sees_taught_class(): void
    {
        // guru: pasangan hanya di kelas 1 (siswa 1-4); siswa 5-6 ada di kelas 2
        $r = $this->actingAs($this->guru)->get('/siswa')->assertOk();
        $r->assertSee('Siswa 1')->assertDontSee('Siswa 5');
        $this->actingAs($this->guru)->get('/siswa?class=2')->assertOk()->assertDontSee('Siswa 5');
    }

    public function test_guru_cannot_open_student_of_other_class(): void
    {
        $this->actingAs($this->guru)->get('/siswa?id=1')->assertOk();
        $this->actingAs($this->guru)->get('/siswa?id=5')->assertForbidden();
    }

    public function test_class_filter_lists_only_own_classes(): void
    {
        $html = $this->actingAs($this->guru)->get('/siswa')->getContent();
        preg_match('/<select name="class".*?<\/select>/s', $html, $m);
        $this->assertStringNotContainsString('value="2"', $m[0]);
    }

    public function test_admin_and_kepsek_see_everyone(): void
    {
        $this->actingAs($this->admin)->get('/siswa?id=5')->assertOk();
        $this->actingAs($this->kepsek)->get('/siswa?id=5')->assertOk();
        $this->actingAs($this->bk)->get('/siswa?id=5')->assertOk();
    }

    public function test_wali_sees_own_class_only(): void
    {
        $this->actingAs($this->wali)->get('/siswa?id=2')->assertOk();
        $this->actingAs($this->wali)->get('/siswa?id=5')->assertForbidden();
    }
}
