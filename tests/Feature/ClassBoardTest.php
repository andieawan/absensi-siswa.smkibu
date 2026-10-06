<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassBoardToken;
use App\Support\Dates;
use Tests\TestCase;

/** Tautan Info Kehadiran Kelas untuk wali murid (dibuat wali kelas, baca-saja). */
class ClassBoardTest extends TestCase
{
    protected function markAtt(int $id, int $student, string $status, ?int $subject = null, ?string $tgl = null, int $class = 1): void
    {
        Attendance::create(['id' => $id, 'student_id' => $student, 'class_id' => $class, 'subject_id' => $subject, 'tanggal' => $tgl ?? Dates::today(),
            'status' => $status, 'notes' => 'RAHASIA-CATATAN', 'recorded_by' => $this->wali->id, 'created_at' => 'x', 'updated_at' => 'x', 'created_at_millis' => 1]);
    }

    private function token(): string
    {
        $this->actingAs($this->wali)->post('/absensi/info-kelas', ['mode' => 'wali', 'class' => 1])->assertRedirect();

        return ClassBoardToken::where('class_id', 1)->where('status', 'aktif')->firstOrFail()->token;
    }

    public function test_wali_creates_one_reusable_link(): void
    {
        $t = $this->token();
        $this->assertSame($t, $this->token());
        $this->assertSame(1, ClassBoardToken::where('class_id', 1)->count());
        $this->actingAs($this->wali)->get('/absensi?mode=wali&tab=riwayat')->assertOk()->assertSee(route('board', $t));
    }

    private function check(string $t, string $nis, string $pin)
    {
        return $this->post(route('board.check', $t), ['nis' => $nis, 'pin' => $pin]);
    }

    public function test_parent_sees_only_own_child_after_verification(): void
    {
        \App\Models\Student::find(2)->update(['telp_ortu' => '6281234560002']);
        \App\Models\Student::find(3)->update(['telp_ortu' => '6281234560003']);
        $this->markAtt(801, 2, 'S');
        $this->markAtt(802, 3, 'A');
        $t = $this->token();
        $nis2 = \App\Models\Student::find(2)->nis;
        $r = $this->check($t, $nis2, '0002')->assertOk();
        $r->assertSee('Siswa 2')->assertSee('Sakit')->assertDontSee('Siswa 3');
        $this->get(route('board', $t))->assertOk()->assertDontSee('Siswa 2')->assertDontSee('Siswa 3');
    }

    public function test_wrong_pin_unknown_nis_or_no_phone_fail_identically(): void
    {
        \App\Models\Student::find(2)->update(['telp_ortu' => '6281234560002']);
        $t = $this->token();
        $nis2 = \App\Models\Student::find(2)->nis;
        $nis3 = \App\Models\Student::find(3)->nis; // tanpa nomor orang tua
        foreach ([[$nis2, '9999'], ['000000', '0002'], [$nis3, '0000'], [$nis2, '']] as [$n, $p]) {
            $this->check($t, $n, $p)->assertOk()->assertSee('tidak cocok')->assertDontSee('Siswa 2');
        }
    }

    public function test_other_class_student_cannot_be_checked(): void
    {
        \App\Models\Student::find(5)->update(['telp_ortu' => '6281234560005']);
        $t = $this->token(); // token kelas 1; siswa 5 ada di kelas 2
        $this->check($t, \App\Models\Student::find(5)->nis, '0005')->assertSee('tidak cocok');
    }

    public function test_check_is_throttled(): void
    {
        $t = $this->token();
        for ($i = 0; $i < 8; $i++) {
            $this->check($t, 'x', '0000')->assertOk();
        }
        $this->check($t, 'x', '0000')->assertStatus(429);
    }

    public function test_revoked_or_unknown_token_is_404(): void
    {
        $t = $this->token();
        $this->actingAs($this->wali)->post("/absensi/info-kelas/$t/cabut", ['mode' => 'wali', 'class' => 1])->assertRedirect();
        $this->get(route('board', $t))->assertNotFound();
        $this->check($t, '1', '0000')->assertNotFound();
        $this->get('/kelas/ik_tidakada')->assertNotFound();
    }

    public function test_only_own_wali_or_admin_can_manage(): void
    {
        $this->actingAs($this->guru)->post('/absensi/info-kelas', ['mode' => 'wali', 'class' => 1]);
        $this->assertSame(0, ClassBoardToken::count());
        $t = $this->token();
        $this->actingAs($this->guru)->post("/absensi/info-kelas/$t/cabut", ['mode' => 'mapel', 'class' => 1]);
        $this->assertSame('aktif', ClassBoardToken::find($t)->status);
    }

}
