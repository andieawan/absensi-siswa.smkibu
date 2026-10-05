<?php

namespace Tests\Feature;

use App\Models\MutasiSiswa;
use App\Models\StaffAttendance;
use App\Models\Student;
use App\Models\TuSurat;
use App\Models\User;
use App\Support\Dates;
use App\Support\Passwords;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Modul Tata Usaha: hak akses, data siswa, mutasi, surat bernomor otomatis, register, cetak, absensi guru & staf. */
class TuModuleTest extends TestCase
{
    private User $tu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tu = User::create(['username' => 'tu', 'nama' => 'Bu Tata', 'password_hash' => Passwords::make('password123'), 'roles' => ['tu'], 'is_active' => true]);
    }

    public function test_access_matrix(): void
    {
        foreach (['/tu', '/tu/siswa', '/tu/mutasi', '/tu/surat/keluar', '/tu/surat/masuk', '/tu/absen-guru', '/tu/absen-guru/rekap', '/tu/cetak'] as $u) {
            $this->actingAs($this->tu)->get($u)->assertOk();
        }
        // TU tidak masuk ke Admin Panel, BK, atau kenaikan kelas
        foreach (['/admin/guru', '/admin/kenaikan', '/bk', '/bk/presensi', '/rekap'] as $u) {
            $this->actingAs($this->tu)->get($u)->assertForbidden();
        }
        // Guru & wali kelas biasa: tidak ada akses
        $this->actingAs($this->guru)->get('/tu')->assertForbidden();
        $this->actingAs($this->wali)->get('/tu/surat/masuk')->assertForbidden();
        // Kepsek: hanya lihat
        $this->actingAs($this->kepsek)->get('/tu')->assertOk();
        $this->actingAs($this->kepsek)->get('/tu/surat/masuk')->assertOk();
        $this->actingAs($this->kepsek)->get('/tu/absen-guru/rekap')->assertOk();
        $this->actingAs($this->kepsek)->get('/tu/siswa')->assertForbidden();
        $this->actingAs($this->kepsek)->post('/tu/absen-guru', ['date' => Dates::today()])->assertForbidden();
        $this->actingAs($this->kepsek)->post('/tu/surat/masuk', [])->assertForbidden();
        $this->actingAs($this->admin)->get('/tu')->assertOk();
        // Akun TU murni langsung diarahkan ke halaman kerjanya
        $this->actingAs($this->tu)->get('/')->assertRedirect('/tu');
        $this->assertSame('Tata Usaha', $this->tu->roleLabel());
        $this->assertArrayHasKey('tu', \App\Services\AdminService::ROLES);
    }

    public function test_tu_manages_student_data_including_parent_contact(): void
    {
        $this->actingAs($this->tu)->post('/tu/siswa', ['nis' => 'N-77', 'nama' => 'Rina', 'jk' => 'P', 'class_id' => 1, 'status' => 'aktif', 'nama_ortu' => 'Bu Rina', 'telp_ortu' => '081234567890'])->assertSessionHas('success');
        $s = Student::where('nis', 'N-77')->first();
        $this->assertSame('6281234567890', $s->telp_ortu);
        $this->actingAs($this->tu)->put("/tu/siswa/{$s->id}", ['nama' => 'Rina S', 'jk' => 'P', 'class_id' => 2, 'status' => 'aktif'])->assertSessionHas('success');
        $this->assertSame([2, 'Rina S'], [$s->fresh()->class_id, $s->fresh()->nama]);
        $this->actingAs($this->tu)->get('/tu/siswa')->assertOk()->assertSee('Rina S')->assertSee('Mutasi');
    }

    public function test_mutasi_keluar_changes_status_and_creates_numbered_letter(): void
    {
        $before = Student::active()->count();
        $this->actingAs($this->tu)->post('/tu/mutasi/keluar', ['student_id' => 5, 'status' => 'pindah', 'tanggal' => Dates::today(), 'sekolah' => 'SMK Negeri 1 Jember', 'alasan' => 'Ikut orang tua', 'buat_surat' => 1])
            ->assertSessionHas('success')->assertSessionHas('print_url');
        $this->assertSame('pindah', Student::find(5)->status);
        $this->assertSame($before - 1, Student::active()->count());
        $m = MutasiSiswa::sole();
        $this->assertSame(['keluar', 'pindah', 'SMK Negeri 1 Jember'], [$m->jenis, $m->status_baru, $m->sekolah]);
        $surat = TuSurat::sole();
        $this->assertMatchesRegularExpression('#^001/MUT/SMKIBU/[IVX]+/\d{4}$#', $surat->nomor);
        $this->assertSame($surat->nomor, $m->nomor_surat);
        $this->actingAs($this->tu)->get('/tu/mutasi')->assertOk()->assertSee('Siswa 5')->assertSee('SMK Negeri 1 Jember');
        // Siswa yang sudah keluar tidak bisa dimutasi lagi; pindah wajib sekolah tujuan
        $this->actingAs($this->tu)->post('/tu/mutasi/keluar', ['student_id' => 5, 'status' => 'berhenti', 'tanggal' => Dates::today()])->assertSessionHas('error');
        $this->actingAs($this->tu)->post('/tu/mutasi/keluar', ['student_id' => 6, 'status' => 'pindah', 'tanggal' => Dates::today()])->assertSessionHas('error');
        $this->assertSame('aktif', Student::find(6)->status);
        $this->actingAs($this->tu)->post('/tu/mutasi/keluar', ['student_id' => 6, 'status' => 'berhenti', 'tanggal' => '2999-01-01'])->assertSessionHas('error');
        $this->actingAs($this->tu)->post('/tu/mutasi/keluar', ['student_id' => 6, 'status' => 'berhenti', 'tanggal' => Dates::today()])->assertSessionHas('success');
        $this->assertSame('berhenti', Student::find(6)->status);
        $this->assertSame(1, TuSurat::count());
    }

    public function test_mutasi_masuk_creates_student(): void
    {
        $p = ['nis' => 'P-1', 'nama' => 'Dimas Pindahan', 'jk' => 'L', 'class_id' => 2, 'tanggal' => Dates::today(), 'sekolah' => 'SMK Maju', 'nama_ortu' => 'Pak Dimas', 'telp_ortu' => '0857-1111-2222'];
        $this->actingAs($this->tu)->post('/tu/mutasi/masuk', $p)->assertSessionHas('success');
        $s = Student::where('nis', 'P-1')->first();
        $this->assertSame([2, 'aktif', '6285711112222'], [$s->class_id, $s->status, $s->telp_ortu]);
        $this->assertSame('masuk', MutasiSiswa::sole()->jenis);
        $this->actingAs($this->tu)->post('/tu/mutasi/masuk', $p)->assertSessionHas('error');            // NIS ganda
        $this->actingAs($this->tu)->post('/tu/mutasi/masuk', ['sekolah' => ''] + ['nis' => 'P-2'] + $p)->assertSessionHas('error');  // sekolah asal wajib
        $this->assertSame(1, MutasiSiswa::count());
        $this->assertSame(0, Student::where('nis', 'P-2')->count());
    }

    public function test_certificates_get_sequential_numbers_per_direction_and_year(): void
    {
        $mk = fn (array $o = []) => $this->actingAs($this->tu)->post('/tu/surat-keterangan', array_merge(['jenis' => 'aktif', 'student_id' => 1, 'tanggal' => '2026-10-05', 'keperluan' => 'beasiswa'], $o));
        $mk()->assertSessionHas('success');
        $mk(['student_id' => 2])->assertSessionHas('success');
        $this->assertSame(['001/KET/SMKIBU/X/2026', '002/KET/SMKIBU/X/2026'], TuSurat::orderBy('urut')->pluck('nomor')->all());
        // Surat keluar biasa memakai nomor agenda berikutnya di arah yang sama
        $this->actingAs($this->tu)->post('/tu/surat/keluar', ['tanggal' => '2026-10-06', 'pihak' => 'Dinas Pendidikan', 'perihal' => 'Laporan bulanan', 'isi' => 'Bersama ini kami kirimkan laporan.'])->assertSessionHas('success');
        $this->assertSame('003/UM/SMKIBU/X/2026', TuSurat::where('perihal', 'Laporan bulanan')->value('nomor'));
        // Surat masuk punya urutan sendiri; nomor = nomor surat dari pengirim
        $this->actingAs($this->tu)->post('/tu/surat/masuk', ['tanggal' => '2026-10-06', 'pihak' => 'Dinas Pendidikan', 'nomor' => '421/123/2026', 'perihal' => 'Undangan rapat'])->assertSessionHas('success');
        $in = TuSurat::where('arah', 'masuk')->sole();
        $this->assertSame([1, '421/123/2026', 'Baru'], [$in->urut, $in->nomor, $in->status]);
        // Tahun berbeda mulai lagi dari 1
        $mk(['tanggal' => '2025-12-31', 'student_id' => 3])->assertSessionHas('success');
        $old = TuSurat::where('tahun', 2025)->sole();
        $this->assertSame([1, '001/KET/SMKIBU/XII/2025'], [$old->urut, $old->nomor]);
        // Nomor manual boleh menimpa
        $this->actingAs($this->tu)->post('/tu/surat/keluar', ['tanggal' => '2026-10-07', 'pihak' => 'Polsek', 'perihal' => 'Izin kegiatan', 'nomor' => '005/MANUAL/2026'])->assertSessionHas('success');
        $this->assertSame('005/MANUAL/2026', TuSurat::where('perihal', 'Izin kegiatan')->value('nomor'));
        $this->actingAs($this->tu)->get('/tu/surat/keluar?tahun=2026')->assertOk()->assertSee('001/KET/SMKIBU/X/2026')->assertDontSee('001/KET/SMKIBU/XII/2025');
    }

    public function test_certificate_validation_and_printing(): void
    {
        $post = fn (array $o) => $this->actingAs($this->tu)->post('/tu/surat-keterangan', array_merge(['student_id' => 1, 'tanggal' => '2026-10-05'], $o));
        $post(['jenis' => 'rahasia'])->assertSessionHas('error');
        $post(['jenis' => 'dispensasi', 'mulai' => '2026-10-06', 'alasan' => ''])->assertSessionHas('error');
        $post(['jenis' => 'dispensasi', 'mulai' => '2026-10-08', 'sampai' => '2026-10-06', 'alasan' => 'Lomba'])->assertSessionHas('error');
        $post(['jenis' => 'pindah', 'sekolah_tujuan' => ''])->assertSessionHas('error');
        $this->assertSame(0, TuSurat::count());

        $post(['jenis' => 'aktif', 'keperluan' => 'pengajuan beasiswa']);
        $post(['jenis' => 'dispensasi', 'mulai' => '2026-10-06', 'sampai' => '2026-10-08', 'alasan' => 'mengikuti lomba poster']);
        $post(['jenis' => 'izin', 'mulai' => '2026-10-09', 'alasan' => 'acara keluarga']);
        $post(['jenis' => 'pindah', 'sekolah_tujuan' => 'SMK Maju', 'alasan' => 'pindah domisili']);
        $this->assertSame(4, TuSurat::count());
        $text = fn (string $j) => $this->actingAs($this->tu)->get('/tu/surat/'.TuSurat::where('jenis', $j)->value('id').'/cetak?tempat=Pakusari')->assertOk();
        $text('aktif')->assertSee('SURAT KETERANGAN SISWA AKTIF')->assertSee('Siswa 1')->assertSee('pengajuan beasiswa')->assertSee('N-1');
        $text('dispensasi')->assertSee('SURAT DISPENSASI')->assertSee('mengikuti lomba poster')->assertSee('s.d.');
        $text('izin')->assertSee('SURAT IZIN')->assertSee('acara keluarga');
        $text('pindah')->assertSee('SMK Maju')->assertSee('pindah domisili')->assertSee('Pakusari');
        // Kepsek boleh membuka cetakan (baca saja)
        $this->actingAs($this->kepsek)->get('/tu/surat/'.TuSurat::first()->id.'/cetak')->assertOk();
    }

    public function test_incoming_register_status_scan_and_lock(): void
    {
        Storage::fake('local');
        $this->actingAs($this->tu)->post('/tu/surat/masuk', ['tanggal' => Dates::today(), 'pihak' => 'Kemendikdasmen', 'perihal' => 'Edaran Dapodik', 'berkas' => UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf')])->assertSessionHas('success');
        $s = TuSurat::sole();
        Storage::disk('local')->assertExists($s->berkas_path);
        $this->actingAs($this->tu)->get("/tu/surat/{$s->id}/berkas")->assertOk();
        $this->actingAs($this->guru)->get("/tu/surat/{$s->id}/berkas")->assertForbidden();
        $this->actingAs($this->tu)->put("/tu/surat/{$s->id}", ['tanggal' => $s->tanggal, 'pihak' => 'Kemendikdasmen', 'perihal' => 'Edaran Dapodik', 'status' => 'Selesai', 'disposisi' => 'Operator → Waka'])->assertSessionHas('success');
        $this->assertSame(['Selesai', 'Operator → Waka'], [$s->fresh()->status, $s->fresh()->disposisi]);
        $this->actingAs($this->tu)->post('/tu/surat/masuk', ['tanggal' => Dates::today(), 'pihak' => 'x', 'perihal' => 'y', 'berkas' => UploadedFile::fake()->create('a.exe', 5)])->assertSessionHasErrors('berkas');
        // Surat > 7 hari terkunci untuk TU, tetapi Admin boleh menghapus
        $old = TuService_make($this, $this->daysAgo(30));
        $this->actingAs($this->tu)->delete("/tu/surat/{$old->id}")->assertSessionHas('error');
        $this->assertNotNull(TuSurat::find($old->id));
        $this->actingAs($this->admin)->delete("/tu/surat/{$old->id}")->assertSessionHas('success');
        $this->assertNull(TuSurat::find($old->id));
        $this->actingAs($this->tu)->delete("/tu/surat/{$s->id}")->assertSessionHas('success');
        Storage::disk('local')->assertMissing($s->berkas_path);
    }

    public function test_staff_attendance_save_update_and_rules(): void
    {
        $today = Dates::today();
        $r = $this->actingAs($this->tu)->get('/tu/absen-guru')->assertOk();
        $names = $r->viewData('staff')->pluck('username')->sort()->values()->all();
        $this->assertSame(['bk', 'guru', 'kepsek', 'tu', 'wali'], $names);   // Administrator (akun teknis) tidak ikut

        $status = [$this->wali->id => 'S', $this->guru->id => 'D', $this->bk->id => 'A'];
        $this->actingAs($this->tu)->post('/tu/absen-guru', ['date' => $today, 'status' => $status, 'notes' => [$this->wali->id => 'Demam']])->assertSessionHas('success');
        $this->assertSame(5, StaffAttendance::where('tanggal', $today)->count());
        $this->assertSame(['S', 'Demam'], [StaffAttendance::where('user_id', $this->wali->id)->value('status'), StaffAttendance::where('user_id', $this->wali->id)->value('notes')]);
        $this->assertSame('H', StaffAttendance::where('user_id', $this->kepsek->id)->value('status'));
        // Simpan ulang memperbarui, tidak menggandakan
        $this->actingAs($this->tu)->post('/tu/absen-guru', ['date' => $today, 'status' => [$this->wali->id => 'H']])->assertSessionHas('success');
        $this->assertSame(5, StaffAttendance::where('tanggal', $today)->count());
        $this->assertSame('H', StaffAttendance::where('user_id', $this->wali->id)->value('status'));
        // Status tidak dikenal jatuh ke Hadir
        $this->actingAs($this->tu)->post('/tu/absen-guru', ['date' => $today, 'status' => [$this->guru->id => 'Z']]);
        $this->assertSame('H', StaffAttendance::where('user_id', $this->guru->id)->value('status'));
        // Aturan tanggal
        $this->actingAs($this->tu)->post('/tu/absen-guru', ['date' => $this->daysAgo(-3)])->assertSessionHas('error');
        $this->actingAs($this->tu)->post('/tu/absen-guru', ['date' => $this->daysAgo(20)])->assertSessionHas('error');
        $this->assertSame(0, StaffAttendance::where('tanggal', $this->daysAgo(20))->count());
        $this->actingAs($this->admin)->post('/tu/absen-guru', ['date' => $this->daysAgo(20)])->assertSessionHas('success');
        // Kepsek hanya melihat (tanpa tombol simpan)
        $this->actingAs($this->kepsek)->get('/tu/absen-guru')->assertOk()->assertDontSee('Simpan Absensi');
        $this->actingAs($this->tu)->get('/tu/absen-guru')->assertSee('Simpan Absensi');
    }

    public function test_staff_monthly_recap_and_export(): void
    {
        foreach ([['2026-03-02', 'H'], ['2026-03-03', 'H'], ['2026-03-04', 'D'], ['2026-03-05', 'S'], ['2026-04-01', 'A']] as [$d, $st]) {
            StaffAttendance::create(['user_id' => $this->wali->id, 'tanggal' => $d, 'status' => $st]);
        }
        $r = $this->actingAs($this->tu)->get('/tu/absen-guru/rekap?bulan=2026-03')->assertOk()->assertSee('Maret 2026');
        $row = collect($r->viewData('rows'))->first(fn ($x) => $x['user']->id === $this->wali->id);
        $this->assertSame([2, 1, 1, 0, 4, 75.0], [$row['H'], $row['D'], $row['S'], $row['A'], $row['total'], $row['rate']]);   // April tidak ikut
        $this->actingAs($this->tu)->get('/tu/absen-guru/rekap?bulan=2026-03&cetak=1')->assertOk()->assertSee('REKAP KEHADIRAN GURU');
        $x = $this->actingAs($this->tu)->get('/tu/absen-guru/rekap/unduh?bulan=2026-03')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));
        $this->actingAs($this->guru)->get('/tu/absen-guru/rekap')->assertForbidden();
    }

    public function test_print_documents(): void
    {
        $this->actingAs($this->tu)->get('/tu/cetak')->assertOk()->assertSee('Daftar Hadir Kosong')->assertSee('Kartu Siswa');
        $sheet = $this->actingAs($this->tu)->get('/tu/cetak/daftar-hadir?class=1&bulan=2026-02')->assertOk()->assertSee('DAFTAR HADIR SISWA')->assertSee('Siswa 1')->assertSee('Februari 2026');
        $this->assertSame(24, count($sheet->viewData('days')));   // Februari 2026: 28 hari − 4 hari Minggu
        $this->actingAs($this->tu)->get('/tu/cetak/daftar-siswa?class=2&kolom=2&judul=Daftar Iuran')->assertOk()->assertSee('DAFTAR IURAN')->assertSee('Siswa 5')->assertDontSee('Siswa 1<');
        $this->actingAs($this->tu)->get('/tu/cetak/kartu?class=1')->assertOk()->assertSee('KARTU SISWA')->assertSee('N-1');
        $this->actingAs($this->tu)->get('/tu/cetak/kartu?class=999')->assertNotFound();
        $this->actingAs($this->wali)->get('/tu/cetak/kartu?class=1')->assertForbidden();
    }
}

/** Buat surat keluar bertanggal tertentu langsung lewat layanan. */
function TuService_make(TuModuleTest $t, string $tanggal): TuSurat
{
    return \App\Services\TuService::saveSurat(User::where('username', 'tu')->first(), 'keluar', null, ['tanggal' => $tanggal, 'pihak' => 'Lama', 'perihal' => 'Surat lama']);
}
