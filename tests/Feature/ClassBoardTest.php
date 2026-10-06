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

    public function test_page_lists_only_absent_students_without_notes(): void
    {
        $this->markAtt(801, 1, 'H');
        $this->markAtt(802, 2, 'S');
        $this->markAtt(803, 3, 'A');
        $this->markAtt(804, 4, 'A', 1);               // absen mapel: tidak dihitung
        $this->markAtt(805, 5, 'A', null, null, 2);   // kelas lain
        $t = $this->token();
        $r = $this->get(route('board', $t))->assertOk();
        $r->assertSee('Siswa 2')->assertSee('Siswa 3')->assertDontSee('Siswa 1')->assertDontSee('Siswa 4')->assertDontSee('Siswa 5')->assertDontSee('RAHASIA-CATATAN');
    }

    public function test_no_session_message_and_all_present(): void
    {
        $t = $this->token();
        $this->get(route('board', $t))->assertOk()->assertSee('belum dicatat');
        $this->markAtt(810, 1, 'H');
        $this->get(route('board', $t))->assertOk()->assertSee('semua siswa masuk');
    }

    public function test_revoked_or_unknown_token_is_404(): void
    {
        $t = $this->token();
        $this->actingAs($this->wali)->post("/absensi/info-kelas/$t/cabut", ['mode' => 'wali', 'class' => 1])->assertRedirect();
        $this->get(route('board', $t))->assertNotFound();
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

    public function test_old_or_future_date_falls_back_to_today(): void
    {
        $this->markAtt(820, 2, 'A', null, date('Y-m-d', strtotime('-30 day')));
        $t = $this->token();
        $this->get(route('board', $t).'?tgl='.date('Y-m-d', strtotime('-30 day')))->assertOk()->assertDontSee('Siswa 2');
    }
}
