<?php

namespace Tests\Feature;

use App\Models\ParentToken;
use Tests\TestCase;

class StudentTest extends TestCase
{
    public function test_halaman_360_dan_akses_wali_murid(): void
    {
        $this->mark(1, $this->daysAgo(2), 'A');
        $this->actingAs($this->wali)->get('/siswa?id=1')->assertOk()->assertSee('Syarat Kehadiran Minimal 85%')->assertSee('Akses Portal Wali Murid');
        $this->actingAs($this->guru)->get('/siswa?id=1')->assertOk()->assertDontSee('Akses Portal Wali Murid');
        $this->actingAs($this->guru)->post('/siswa/1/akses-wali')->assertSessionHas('error');
        $this->assertSame(0, ParentToken::count());

        $this->actingAs($this->wali)->post('/siswa/1/akses-wali')->assertSessionHas('new_token');
        $t = ParentToken::firstOrFail()->token;
        auth()->logout();
        $this->get("/wali/$t")->assertOk()->assertSee('Siswa 1')->assertSee('Alpa')->assertDontSee('Dossier Nilai')->assertDontSee('Siswa 2');
        $this->get("/?wali=$t")->assertRedirect("/wali/$t");

        $this->actingAs($this->wali)->post("/siswa/akses-wali/$t/cabut")->assertSessionHas('success');
        auth()->logout();
        $this->get("/wali/$t")->assertNotFound()->assertSee('dicabut');
    }

    public function test_pengesahan_akademik_85_persen(): void
    {
        $this->mark(1, $this->daysAgo(1), 'H');
        $this->actingAs($this->wali)->post('/siswa/1/pengesahan')->assertSessionHas('success', fn ($m) => str_contains($m, 'DISETUJUI'));

        $this->mark(2, $this->daysAgo(1), 'A');
        $this->post('/siswa/2/pengesahan')->assertSessionHas('error', fn ($m) => str_contains($m, 'ditolak'));
        $this->post('/siswa/2/pengesahan', ['override' => 1, 'reason' => 'medis'])->assertSessionHas('error', fn ($m) => str_contains($m, 'khusus Administrator'));
        $this->actingAs($this->kepsek)->post('/siswa/2/pengesahan', ['override' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'Alasan'));
        $this->post('/siswa/2/pengesahan', ['override' => 1, 'reason' => 'rawat inap'])->assertSessionHas('success', fn ($m) => str_contains($m, 'dispensasi'));
    }

    public function test_surat_hanya_untuk_yang_berwenang(): void
    {
        $this->mark(1, $this->daysAgo(1), 'A');
        $this->actingAs($this->wali)->get('/surat/peringatan/1')->assertOk()->assertSee('SURAT PERINGATAN');
        $this->actingAs($this->bk)->get('/surat/panggilan/1?tgl=Senin&jam=09.00')->assertOk()->assertSee('Undangan Wali Murid')->assertSee('09.00');
        $this->actingAs($this->guru)->get('/surat/peringatan/1')->assertForbidden();
        $this->actingAs($this->guru)->get('/surat/laporan?class=1&subject=2')->assertOk()->assertSee('LAPORAN EVALUASI');
    }

    public function test_bk_manual_boleh_mundur_lebih_7_hari(): void
    {
        $old = $this->daysAgo(20);
        $this->actingAs($this->bk)->post('/bk/presensi', ['student' => 1, 'status' => 'I', 'date' => $old, 'notes' => 'konseling'])->assertSessionHas('success');
        $this->assertDatabaseHas('attendance', ['student_id' => 1, 'tanggal' => $old, 'recorded_via' => 'bk_manual']);
        $this->actingAs($this->wali)->post('/bk/presensi', ['student' => 1, 'status' => 'I', 'date' => $old])->assertForbidden();
        $this->actingAs($this->bk)->get('/bk/presensi?class=1')->assertOk()->assertSee('Rekap Ketidakhadiran');
        $this->assertStringStartsWith('PK', $this->get('/unduh/bk?class=1')->getContent());
    }

    public function test_semua_jenis_surat_resmi_tampil(): void
    {
        $this->actingAs($this->wali)->get('/surat/panggilan/1?no=7&hari=Sabtu, 10 Oktober 2026')->assertOk()
            ->assertSee('400.3.8.1/007/101.6.20570966/', false)->assertSee('MEMBAWA FC KK DAN KTP ORANGTUA/WALI')->assertSee('NSS : 342052423288', false);
        foreach (['teguran' => 'SURAT TEGURAN TERTULIS', 'pernyataan-berhenti' => 'SIAP DI BERHENTIKAN', 'pernyataan-mundur' => 'MENGUNDURKAN DIRI',
            'berita-acara' => 'BERITA ACARA PEMANGGILAN', 'izin' => 'SURAT IZIN MENINGGALKAN SEKOLAH'] as $j => $teks) {
            $this->actingAs($this->wali)->get("/surat/{$j}/1")->assertOk()->assertSee($teks, false);
            $this->actingAs($this->guru)->get("/surat/{$j}/1")->assertForbidden();
        }
        $this->actingAs($this->wali)->get('/surat/izin/1?tglk=2026-10-10&alasan=berobat&salinan=2')->assertOk()->assertSee('SABTU')->assertSee('BEROBAT');
    }

    public function test_bk_bisa_cetak_semua_surat_dari_halaman_bk(): void
    {
        foreach (['/bk', '/bk/catatan/kasus', '/bk/presensi'] as $u) {
            $this->actingAs($this->bk)->get($u)->assertOk();
        }
        foreach (['panggilan', 'peringatan', 'teguran', 'pernyataan-berhenti', 'pernyataan-mundur', 'berita-acara', 'izin'] as $j) {
            $this->actingAs($this->bk)->get($j === 'panggilan' || $j === 'peringatan' ? '/surat/'.$j.'/1' : "/surat/{$j}/1")->assertOk();
        }
    }
}
