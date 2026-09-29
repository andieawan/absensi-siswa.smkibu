<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\DelegationToken;
use App\Support\Dates;
use Tests\TestCase;

class DelegationTest extends TestCase
{
    private function token(): string
    {
        $this->actingAs($this->wali)->post('/absensi/delegasi', ['mode' => 'wali', 'class' => 1, 'hours' => 6])->assertSessionHas('new_token');

        return DelegationToken::firstOrFail()->token;
    }

    public function test_alur_delegasi_ketua_kelas(): void
    {
        $t = $this->token();
        $this->assertStringStartsWith('kk_', $t);
        $this->assertLessThanOrEqual(6 * 3600000 + 5000, DelegationToken::find($t)->expires_at_millis - Dates::nowMillis());
        auth()->logout();
        $this->get("/presensi/$t")->assertOk()->assertSee('Presensi Harian')->assertSee('Siswa 1')->assertDontSee('Siswa 5');
        $this->get("/?token=$t")->assertRedirect("/presensi/$t");
        $y = $this->daysAgo(1);
        $this->post("/presensi/$t", ['date' => $y, 'status' => $this->statuses([2 => 'I'])])->assertSessionHas('success');
        $this->assertSame(4, Attendance::where(['tanggal' => $y, 'recorded_via' => 'ketua_kelas_delegasi'])->count());
    }

    public function test_guru_bukan_wali_tidak_bisa_membuat_delegasi(): void
    {
        $this->actingAs($this->guru)->post('/absensi/delegasi', ['mode' => 'wali', 'class' => 1])->assertSessionHas('error');
        $this->assertSame(0, DelegationToken::count());
    }

    public function test_penolakan_delegasi(): void
    {
        $t = $this->token();
        auth()->logout();
        $this->get('/presensi/kk_palsu')->assertStatus(410)->assertSee('tidak ditemukan');
        $this->post("/presensi/$t", ['date' => Dates::today(), 'status' => [5 => 'H']])->assertSessionHas('error', fn ($m) => str_contains($m, 'bukan anggota kelas'));
        $this->post("/presensi/$t", ['date' => $this->daysAgo(10), 'status' => $this->statuses()])->assertSessionHas('error', fn ($m) => str_contains($m, '7 hari'));
        DelegationToken::whereKey($t)->update(['expires_at_millis' => Dates::nowMillis() - 1000]);
        $this->get("/presensi/$t")->assertStatus(410)->assertSee('kedaluwarsa');
        $this->assertSame(0, Attendance::count());
    }
}
