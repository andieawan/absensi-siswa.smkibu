<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Support\Dates;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    private function save($user, string $date, array $status, string $mode = 'wali', ?int $subject = null, int $class = 1)
    {
        return $this->actingAs($user)->post('/absensi', array_filter(['mode' => $mode, 'class' => $class, 'subject' => $subject, 'date' => $date, 'status' => $status, 'notes' => [1 => 'sakit demam']]));
    }

    public function test_wali_simpan_dan_upsert_tanpa_duplikat(): void
    {
        $today = Dates::today();
        $this->save($this->wali, $today, $this->statuses([1 => 'S']))->assertSessionMissing('error');
        $this->save($this->wali, $today, $this->statuses([1 => 'A']));
        $this->assertSame(4, Attendance::where('tanggal', $today)->whereNull('subject_id')->count());
        $r = Attendance::where(['student_id' => 1, 'tanggal' => $today])->first();
        $this->assertSame('A', $r->status);
        $this->assertSame('wali', $r->recorded_via);
        $this->get("/absensi?mode=wali&class=1&date=$today")->assertSee('sakit demam')->assertSee('sudah tercatat');
    }

    public function test_tolak_siswa_kelas_lain_dan_kelas_bukan_wali(): void
    {
        $this->save($this->wali, Dates::today(), [5 => 'H'])->assertSessionHas('error', fn ($m) => str_contains($m, 'bukan anggota kelas'));
        $this->save($this->wali, Dates::today(), [5 => 'H'], 'wali', null, 2);
        $this->assertSame(0, Attendance::count());
    }

    public function test_guru_mapel_butuh_penugasan(): void
    {
        $this->save($this->guru, Dates::today(), $this->statuses(), 'mapel', 2)->assertSessionHas('success');
        $this->assertSame(4, Attendance::where('subject_id', 2)->count());
        $this->save($this->guru, Dates::today(), $this->statuses(), 'mapel', 1)->assertSessionHas('error', fn ($m) => str_contains($m, 'Otorisasi ditolak'));
        $this->assertSame(0, Attendance::where('subject_id', 1)->count());
    }

    public function test_batas_7_hari_dan_masa_depan(): void
    {
        $old = $this->daysAgo(20);
        $this->save($this->wali, $old, $this->statuses())->assertSessionHas('error', fn ($m) => str_contains($m, '7 hari'));
        $this->assertSame(0, Attendance::count());
        $this->post('/absensi', ['mode' => 'wali', 'class' => 1, 'date' => gmdate('Y-m-d', time() + 5 * 86400), 'status' => $this->statuses()]);
        $this->assertSame(0, Attendance::count());
        $this->save($this->admin, $old, $this->statuses())->assertSessionHas('success');
        $this->assertSame(4, Attendance::where('tanggal', $old)->count());
    }

    public function test_peringatan_85_persen(): void
    {
        foreach ([1, 2, 3] as $d) {
            $this->mark(1, $this->daysAgo($d), 'A');
        }
        $this->save($this->wali, Dates::today(), $this->statuses())->assertSessionHas('warning', fn ($m) => str_contains($m, 'Siswa 1'));
    }

    public function test_hapus_sesi_dan_kunci_7_hari(): void
    {
        $y = $this->daysAgo(1);
        $this->save($this->wali, $y, $this->statuses());
        $this->post('/absensi/hapus', ['mode' => 'wali', 'class' => 1, 'tgl' => $y])->assertSessionHas('success');
        $this->assertSame(0, Attendance::count());

        $old = $this->daysAgo(10);
        foreach ([1, 2] as $s) {
            $this->mark($s, $old, 'H');
        }
        $this->actingAs($this->wali)->get('/absensi?mode=wali&class=1&tab=riwayat')->assertSee('Terkunci');
        $this->post('/absensi/hapus', ['mode' => 'wali', 'class' => 1, 'tgl' => $old])->assertSessionHas('error');
        $this->assertSame(2, Attendance::count());
        $this->actingAs($this->admin)->post('/absensi/hapus', ['mode' => 'wali', 'class' => 1, 'tgl' => $old])->assertSessionHas('success');
        $this->assertSame(0, Attendance::count());
    }

    public function test_export_absensi_xlsx(): void
    {
        $this->mark(1, $this->daysAgo(1), 'S');
        $res = $this->actingAs($this->wali)->get('/unduh/absensi?class=1');
        $res->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $res->getContent());
        $this->actingAs($this->guru)->get('/unduh/absensi?class=2')->assertForbidden();
    }
}
