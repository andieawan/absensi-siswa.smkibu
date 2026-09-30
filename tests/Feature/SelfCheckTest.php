<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Models\StaffAttendance;
use App\Services\StaffService;
use App\Support\Dates;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Absen mandiri guru & staf: masuk/pulang/lapor, terlambat, radius lokasi, pengaturan, rekap. */
class SelfCheckTest extends TestCase
{
    private function at(string $hm): void
    {
        $t = CarbonImmutable::parse(Dates::today().' '.$hm.':00', Dates::tz());
        Carbon::setTestNow($t);
        CarbonImmutable::setTestNow($t);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function geo(int $radius = 200): void
    {
        SchoolSetting::put(['staff_lat' => -8.0, 'staff_lng' => 113.0, 'staff_radius' => $radius]);
    }

    public function test_check_in_on_time_and_late_and_out(): void
    {
        $this->at('06:50');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('success');
        $r = StaffAttendance::where('user_id', $this->guru->id)->sole();
        $this->assertSame(['H', '06:50', false, 'mandiri'], [$r->status, $r->jam_masuk, $r->terlambat, $r->sumber]);
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('error');   // tidak dobel
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'pulang'])->assertSessionHas('success');
        $this->assertSame('06:50', $r->fresh()->jam_masuk);
        $this->assertNotNull($r->fresh()->jam_pulang);
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'pulang'])->assertSessionHas('error');

        $this->at('07:40');
        $this->actingAs($this->wali)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('success');
        $this->assertTrue(StaffAttendance::where('user_id', $this->wali->id)->value('terlambat'));
    }

    public function test_check_out_needs_check_in_and_unknown_action_rejected(): void
    {
        $this->at('15:00');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'pulang'])->assertSessionHas('error');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'x'])->assertSessionHas('error');
        $this->assertSame(0, StaffAttendance::count());
    }

    public function test_report_leave_requires_note_and_blocks_check_in(): void
    {
        $this->at('06:30');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'S', 'catatan' => ''])->assertSessionHas('error');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'H', 'catatan' => 'halo halo'])->assertSessionHas('error');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'S', 'catatan' => 'Demam'])->assertSessionHas('success');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'I', 'catatan' => 'Urusan keluarga'])->assertSessionHas('success');   // boleh ralat laporan sendiri
        $r = StaffAttendance::where('user_id', $this->guru->id)->sole();
        $this->assertSame(['I', 'Urusan keluarga'], [$r->status, $r->notes]);
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('error');
    }

    public function test_tu_recorded_row_is_respected(): void
    {
        $this->at('08:00');
        $tu = \App\Models\User::create(['username' => 'tu', 'nama' => 'Tata', 'password_hash' => \App\Support\Passwords::make('password123'), 'roles' => ['tu'], 'is_active' => true]);
        $this->actingAs($tu)->post('/tu/absen-guru', ['date' => Dates::today(), 'status' => [$this->guru->id => 'A', $this->wali->id => 'H']])->assertSessionHas('success');
        // Sudah dicatat TU sebagai Alpa: tidak bisa absen/lapor sendiri
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('error');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'I', 'catatan' => 'Izin ya'])->assertSessionHas('error');
        // TU mencatat Hadir tanpa jam: yang bersangkutan masih bisa melengkapi jam masuk
        $this->actingAs($this->wali)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('success');
        $this->assertSame('08:00', StaffAttendance::where('user_id', $this->wali->id)->value('jam_masuk'));
        // TU menyimpan ulang: jam tidak hilang
        $this->actingAs($tu)->post('/tu/absen-guru', ['date' => Dates::today(), 'status' => [$this->wali->id => 'H']]);
        $this->assertSame('08:00', StaffAttendance::where('user_id', $this->wali->id)->value('jam_masuk'));
    }

    public function test_geofence_blocks_far_and_missing_location(): void
    {
        $this->at('07:00');
        $this->geo(200);
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('error');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk', 'lat' => -8.01, 'lng' => 113.0])->assertSessionHas('error');   // ±1,1 km
        $this->assertSame(0, StaffAttendance::count());
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk', 'lat' => -8.0005, 'lng' => 113.0])->assertSessionHas('success');   // ±55 m
        $r = StaffAttendance::sole();
        $this->assertEqualsWithDelta(55, $r->jarak, 3);
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'pulang', 'lat' => -8.02, 'lng' => 113.0])->assertSessionHas('error');
        // Lapor dinas luar tidak memakai lokasi
        $this->actingAs($this->wali)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'D', 'catatan' => 'Rapat dinas'])->assertSessionHas('success');
    }

    public function test_haversine(): void
    {
        $this->assertSame(0, StaffService::distance(-8.0, 113.0, -8.0, 113.0));
        $this->assertEqualsWithDelta(111195, StaffService::distance(0, 0, 1, 0), 30);
    }

    public function test_access_and_disable(): void
    {
        $this->actingAs($this->guru)->get('/absen-saya')->assertOk()->assertSee('Absen Masuk');
        $this->actingAs($this->kepsek)->get('/absen-saya')->assertOk();
        $this->actingAs($this->admin)->get('/absen-saya')->assertForbidden();     // akun teknis tidak diabsen
        $this->actingAs($this->guru)->get('/')->assertOk()->assertSee('Absen Hari Ini');

        SchoolSetting::put(['staff_mandiri' => false]);
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('error');
        $this->actingAs($this->guru)->get('/')->assertOk()->assertDontSee('Absen Hari Ini');
        $this->actingAs($this->guru)->get('/absen-saya')->assertOk()->assertSee('dimatikan');
        $this->assertSame(0, StaffAttendance::count());
    }

    public function test_admin_settings_for_self_checkin(): void
    {
        $base = ['school_name' => 'SMK', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8, 'staff_form' => 1];
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertSee('Absen Mandiri Guru');
        $this->actingAs($this->admin)->put('/admin/pengaturan', $base + ['staff_mandiri' => 1, 'staff_jam_masuk' => '07:30', 'staff_radius' => 150])->assertSessionHas('error');   // radius tanpa koordinat
        $this->actingAs($this->admin)->put('/admin/pengaturan', $base + ['staff_mandiri' => 1, 'staff_jam_masuk' => '07:30', 'staff_lat' => '-8.1', 'staff_lng' => '113.2', 'staff_radius' => 150])->assertSessionHas('success');
        $c = StaffService::config();
        $this->assertSame(['07:30', -8.1, 113.2, 150, true], [$c['batas'], $c['lat'], $c['lng'], $c['radius'], $c['aktif']]);
        $this->actingAs($this->admin)->put('/admin/pengaturan', $base)->assertSessionHas('success');      // tanpa centang = dimatikan
        $this->assertFalse(StaffService::config()['aktif']);
        $this->actingAs($this->wali)->put('/admin/pengaturan', $base)->assertForbidden();
    }

    public function test_recap_counts_late_and_tu_page_shows_times(): void
    {
        $this->at('07:45');
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk']);
        $tu = \App\Models\User::create(['username' => 'tu', 'nama' => 'Tata', 'password_hash' => \App\Support\Passwords::make('password123'), 'roles' => ['tu'], 'is_active' => true]);
        $this->actingAs($tu)->get('/tu/absen-guru')->assertOk()->assertSee('masuk 07:45')->assertSee('terlambat')->assertSee('mandiri');
        $r = $this->actingAs($tu)->get('/tu/absen-guru/rekap?bulan='.substr(Dates::today(), 0, 7))->assertOk()->assertSee('Terlambat');
        $row = collect($r->viewData('rows'))->first(fn ($x) => $x['user']->id === $this->guru->id);
        $this->assertSame([1, 1], [$row['H'], $row['T']]);
    }
}
