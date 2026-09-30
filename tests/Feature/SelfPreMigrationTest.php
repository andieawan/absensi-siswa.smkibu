<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Modul TU versi lama sudah terpasang, tetapi migrasi absen mandiri belum dijalankan: semuanya tetap jalan. */
class SelfPreMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Perubahan struktur tabel hanya bisa dibatalkan otomatis di SQLite.');
        }
        Schema::table('staff_attendance', fn ($t) => $t->dropColumn(['jam_masuk', 'jam_pulang', 'terlambat', 'sumber', 'jarak']));
        Schema::table('school_settings', fn ($t) => $t->dropColumn(['staff_mandiri', 'staff_jam_masuk', 'staff_lat', 'staff_lng', 'staff_radius']));
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', '2026_10_02_000100_add_staff_self_checkin')->delete();
        \App\Models\SchoolSetting::forget();
    }

    public function test_everything_works_without_self_checkin_columns(): void
    {
        $tu = \App\Models\User::create(['username' => 'tu', 'nama' => 'TU', 'password_hash' => \App\Support\Passwords::make('password123'), 'roles' => ['tu'], 'is_active' => true]);
        $today = \App\Support\Dates::today();
        foreach (['/', '/absen-saya'] as $u) {
            $this->actingAs($this->guru)->get($u)->assertOk()->assertDontSee('Absen Hari Ini');
        }
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'masuk'])->assertSessionHas('error');
        $this->actingAs($tu)->get('/tu')->assertOk();
        $this->actingAs($tu)->post('/tu/absen-guru', ['date' => $today, 'status' => [$this->guru->id => 'S']])->assertSessionHas('success');
        $this->actingAs($tu)->get('/tu/absen-guru')->assertOk();
        $this->actingAs($tu)->get('/tu/absen-guru/rekap')->assertOk();
        $this->actingAs($tu)->get('/tu/absen-guru/rekap/unduh')->assertOk();
        $this->actingAs($this->admin)->get('/admin/pengaturan')->assertOk()->assertDontSee('Absen Mandiri Guru');
        $this->actingAs($this->admin)->put('/admin/pengaturan', ['school_name' => 'SMK', 'semester' => 'Ganjil', 'backup_retention_weeks' => 8])->assertSessionHas('success');
        // Setelah "Perbarui Database": aktif
        $this->actingAs($this->admin)->post('/admin/perbarui-database')->assertSessionHas('success');
        $this->assertTrue(\App\Support\DbUpdate::selfReady());
        $this->actingAs($this->guru)->post('/absen-saya', ['aksi' => 'lapor', 'status' => 'I', 'catatan' => 'Urusan'])->assertSessionHas('error');   // sudah dicatat TU (Sakit)
    }
}
