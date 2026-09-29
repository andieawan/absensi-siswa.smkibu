<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Pairing;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\User;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminTest extends TestCase
{
    public function test_kelola_akun_guru(): void
    {
        $this->actingAs($this->admin)->post('/admin/guru', ['nama' => 'Guru Baru', 'username' => 'guru.baru', 'password' => 'Sandi12345', 'roles' => ['guru'], 'kelas_wali_id' => 2, 'subjects' => [1], 'classes' => [2]])
            ->assertSessionHas('success');
        $u = User::where('username', 'guru.baru')->firstOrFail();
        $this->assertSame(2, $u->kelas_wali_id);
        $this->assertGreaterThan(1000, $u->id); // ID dari tabel sequences

        $this->post('/admin/guru', ['nama' => 'X', 'username' => 'guru.baru', 'password' => 'Sandi12345', 'roles' => ['guru']])->assertSessionHas('error', fn ($m) => str_contains($m, 'sudah dipakai'));
        $this->post("/admin/guru/{$u->id}/reset", ['password' => 'Sandi67890'])->assertSessionHas('success');
        $this->post('/logout');
        $this->post('/login', ['username' => 'guru.baru', 'password' => 'Sandi67890'])->assertRedirect('/');

        $this->actingAs($this->admin)->post("/admin/guru/{$u->id}/status")->assertSessionHas('success');
        $this->assertFalse($u->fresh()->is_active);
        $this->post("/admin/guru/{$this->admin->id}/status")->assertSessionHas('error');

        $this->put("/admin/guru/{$u->id}", ['nama' => 'Guru Diubah', 'roles' => ['guru', 'bk'], 'kelas_wali_id' => ''])->assertSessionHas('success');
        $this->assertSame(['guru', 'bk'], $u->fresh()->roles);
        $this->assertNull($u->fresh()->kelas_wali_id);
    }

    public function test_admin_biasa_tidak_bisa_memberi_peran_admin(): void
    {
        $plain = User::create(['username' => 'admin2', 'nama' => 'Admin 2', 'password_hash' => 'x', 'roles' => ['admin'], 'is_active' => true]);
        $this->actingAs($plain)->post('/admin/guru', ['nama' => 'X', 'username' => 'calon.admin', 'password' => 'Sandi12345', 'roles' => ['admin']])->assertSessionHas('error', fn ($m) => str_contains($m, 'Superadmin'));
        $this->post("/admin/guru/{$this->admin->id}/reset", ['password' => 'Sandi12345'])->assertSessionHas('error');
    }

    public function test_kelola_siswa_dan_impor(): void
    {
        $this->actingAs($this->admin)->post('/admin/siswa', ['nis' => 'B-1', 'nama' => 'Baru', 'jk' => 'P', 'class_id' => 1, 'status' => 'aktif'])->assertSessionHas('success');
        $this->post('/admin/siswa', ['nis' => 'B-1', 'nama' => 'Dup', 'jk' => 'P', 'class_id' => 1, 'status' => 'aktif'])->assertSessionHas('error', fn ($m) => str_contains($m, 'sudah terdaftar'));
        $s = Student::where('nis', 'B-1')->firstOrFail();
        $this->put("/admin/siswa/{$s->id}", ['nis' => 'HACK', 'nama' => 'Baru 2', 'jk' => 'L', 'class_id' => 2, 'status' => 'pindah'])->assertSessionHas('success');
        $s->refresh();
        $this->assertSame(['B-1', 'Baru 2', 2, 'pindah'], [$s->nis, $s->nama, $s->class_id, $s->status]);

        $csv = UploadedFile::fake()->createWithContent('siswa.csv', "NIS,Nama Siswa,JK,Kelas\nI-1,Impor Satu,L,X RPL 1\nI-2,Impor Dua,P,Kelas Ngawur\n");
        $this->post('/admin/siswa/impor', ['file' => $csv])->assertSessionHas('warning', fn ($m) => str_contains($m, '1 siswa ditambahkan') && str_contains($m, 'kelas tidak dikenal'));
        $this->assertTrue(Student::where('nis', 'I-1')->exists());

        $xlsx = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($xlsx, Xlsx::build(['S' => [['NIS', 'Nama Siswa', 'JK', 'Kelas'], ['X-9', 'Dari Excel', 'P', 'X TKJ 1']]]));
        $this->post('/admin/siswa/impor', ['file' => new UploadedFile($xlsx, 'siswa.xlsx', null, null, true)])->assertSessionHas('success');
        $this->assertSame(2, Student::where('nis', 'X-9')->value('class_id'));
    }

    public function test_kelas_mapel_pasangan_pengaturan(): void
    {
        $this->actingAs($this->admin)->post('/admin/kelas', ['name' => 'XI BARU', 'jurusan' => 'Uji', 'semester' => 'Ganjil'])->assertSessionHas('success');
        $this->post('/admin/mapel', ['name' => 'Mapel Baru'])->assertSessionHas('success');
        $this->get('/admin/kelas')->assertSee('XI BARU')->assertSee('Mapel Baru');

        $this->post('/admin/pasangan', ['user_id' => $this->wali->id, 'subject_id' => 2, 'class_id' => 2])->assertSessionHas('success');
        $this->assertTrue(Pairing::where(['user_id' => $this->wali->id, 'class_id' => 2])->exists());
        $this->delete('/admin/pasangan', ['user_id' => $this->wali->id, 'subject_id' => 2, 'class_id' => 2]);
        $this->assertFalse(Pairing::where(['user_id' => $this->wali->id, 'class_id' => 2])->exists());

        $this->put('/admin/pengaturan', ['school_name' => 'SMK Uji', 'tahun_ajaran' => '2026/2027', 'semester' => 'Genap', 'kepsek_nama' => 'Bu Kepsek', 'bk_nama' => 'Bu BK', 'backup_retention_weeks' => 4])->assertSessionHas('success');
        SchoolSetting::forget();
        $this->get('/')->assertSee('SMK Uji');
        $this->get('/admin/log')->assertSee('Ubah Pengaturan Sekolah');
    }

    public function test_backup_dan_ekspor(): void
    {
        $this->actingAs($this->admin)->post('/admin/backup')->assertSessionHas('success');
        $file = collect(glob(config('absensi.backup_dir').'/absensi_*'))->map(fn ($f) => basename($f))->first();
        $this->assertNotNull($file);
        $this->get("/admin/backup/$file")->assertOk();
        $this->get('/admin/backup/..%2F..%2F.env')->assertNotFound();
        $this->get('/admin/ekspor-json')->assertOk()->assertDontSee('password_hash');
        $this->actingAs($this->wali)->get('/admin/ekspor-json')->assertForbidden();
        array_map('unlink', glob(config('absensi.backup_dir').'/absensi_*'));
    }

    public function test_upload_hardcopy(): void
    {
        $tpl = $this->actingAs($this->admin)->get('/admin/hardcopy/template?class=1');
        $tpl->assertOk();
        $path = tempnam(sys_get_temp_dir(), 't').'.xlsx';
        file_put_contents($path, $tpl->getContent());
        [$hdr, $rows] = Xlsx::readWithHeader($path, 'a.xlsx');
        $this->assertSame(['no', 'nis', 'nama siswa', 'jk', 'status (h/i/s/a)', 'catatan (opsional)'], $hdr);
        $this->assertCount(4, $rows);

        $csv = "NIS,Status (H/I/S/A),Catatan (Opsional)\nN-1,A,bolos\nN-2,Z,\nTIDAK-ADA,H,\nN-3,S,\n";
        $date = $this->daysAgo(30);
        $this->post('/admin/hardcopy/pratinjau', ['class_id' => 1, 'date' => $date, 'file' => UploadedFile::fake()->createWithContent('h.csv', $csv)])
            ->assertOk()->assertSee('2 valid')->assertSee('2 bermasalah')->assertSee('tidak terdaftar');
        $this->post('/admin/hardcopy/simpan', ['class_id' => 1, 'date' => $date, 'e' => [1 => ['status' => 'A', 'notes' => 'bolos'], 3 => ['status' => 'S']]])->assertSessionHas('success');
        $this->assertSame(2, Attendance::where(['tanggal' => $date, 'recorded_via' => 'upload_hardcopy'])->count());
        $this->assertTrue(AuditLog::where('action', 'Submit Absensi')->exists());
    }
}
