<?php

namespace Tests\Feature;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Admin → Impor Data: template + impor massal. */
class ImportDataTest extends TestCase
{
    private function csv(array $rows, string $name = 'data.csv'): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $r)), $rows);

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines));
    }

    private function run_(string $type, array $rows, bool $dry = false)
    {
        return $this->actingAs($this->admin)->post("/admin/impor/$type", ['file' => $this->csv($rows)] + ($dry ? ['dry' => 1] : []));
    }

    public function test_page_and_templates_download_and_read_back(): void
    {
        $this->actingAs($this->admin)->get('/admin/impor')->assertOk()->assertSee('Unduh template')->assertSee('Guru &amp; Staf', false);
        foreach (['kelas', 'mapel', 'siswa', 'guru', 'pasangan'] as $t) {
            $r = $this->actingAs($this->admin)->get("/admin/impor/$t/template")->assertOk();
            $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));
            $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
            file_put_contents($tmp, $r->getContent());
            [$hdr, $rows] = Xlsx::readWithHeader($tmp, 'x.xlsx');
            $this->assertNotEmpty($hdr);
            $this->assertNotEmpty($rows, "$t: baris contoh harus terbaca (lembar Data pertama)");
            unlink($tmp);
        }
    }

    public function test_only_admin(): void
    {
        $this->actingAs($this->guru)->get('/admin/impor')->assertForbidden();
        $this->actingAs($this->guru)->post('/admin/impor/kelas', ['file' => $this->csv([['Nama Kelas'], ['Z']])])->assertForbidden();
    }

    public function test_import_classes_and_subjects(): void
    {
        $n = SchoolClass::count();
        $this->run_('kelas', [['Nama Kelas', 'Jurusan', 'Angkatan'], ['X DKV 9', 'DKV', 'X'], ['x dkv 9', '', ''], ['', '', '']])->assertSessionHas('import_result', fn ($r) => $r['ok'] === 1 && count($r['skip']) === 1);
        $this->assertSame($n + 1, SchoolClass::count());
        $this->assertNotNull(SchoolClass::where('name', 'X DKV 9')->first()->tahun_ajaran);
        $m = Subject::count();
        $this->run_('mapel', [['Nama Mapel'], ['Seni Budaya'], ['Seni Budaya'], ['Matematika']])->assertSessionHas('import_result', fn ($r) => $r['ok'] === 1 && count($r['skip']) === 2);
        $this->assertSame($m + 1, Subject::count());
    }

    public function test_dry_run_saves_nothing(): void
    {
        $n = SchoolClass::count();
        $this->run_('kelas', [['Nama Kelas'], ['Kelas Coba']], true)->assertSessionHas('import_result', fn ($r) => $r['ok'] === 1 && $r['dry']);
        $this->assertSame($n, SchoolClass::count());
    }

    public function test_import_students(): void
    {
        $cls = SchoolClass::first()->name;
        $this->run_('siswa', [['NIS', 'Nama Siswa', 'JK', 'Kelas'], ['N-900', 'Baru Satu', 'L', $cls], ['N-901', 'Baru Dua', 'P', 'Kelas Gaib'], ['N-1', 'Dobel', 'L', $cls]])
            ->assertSessionHas('import_result', fn ($r) => $r['ok'] === 1 && count($r['skip']) === 2);
        $this->assertNotNull(Student::where('nis', 'N-900')->first());
    }

    public function test_import_teachers_with_generated_password_roles_and_assignments(): void
    {
        $cls = SchoolClass::orderBy('id')->get();
        $sub = Subject::regular()->orderBy('id')->get();
        $this->run_('guru', [
            ['Username', 'Nama', 'Peran', 'Password', 'Wali Kelas', 'Mapel', 'Kelas'],
            ['guru.baru', 'Guru Baru', 'guru', '', $cls[1]->name, $sub[0]->name.', '.$sub[1]->name, $cls[0]->name.', '.$cls[1]->name],
            ['staf.tu', 'Staf TU', 'tu', 'RahasiaKuat99', '', '', ''],
            ['guru.baru', 'Dobel', 'guru', '', '', '', ''],
            ['salah.peran', 'X', 'dewa', '', '', '', ''],
            ['mapel.gaib', 'X', 'guru', '', '', 'Tidak Ada', ''],
            ['pendek', 'X', 'guru', 'abc', '', '', ''],
            ['jadi.admin', 'X', 'admin', '', '', '', ''],
        ])->assertSessionHas('import_result', function ($r) {
            return $r['ok'] === 2 && count($r['skip']) === 5 && isset($r['passwords']['guru.baru']) && strlen($r['passwords']['guru.baru']) === 10 && ! isset($r['passwords']['staf.tu']);
        });
$g = User::where('username', 'guru.baru')->first();
        $this->assertSame(['guru'], $g->roles);
        $this->assertSame($cls[1]->id, $g->kelas_wali_id);
        $this->assertEqualsCanonicalizing([$sub[0]->id, $sub[1]->id], $g->subjects);
        $this->assertSame(['tu'], User::where('username', 'staf.tu')->first()->roles);
        $this->assertNull(User::where('username', 'jadi.admin')->first());
        // password buatan bisa dipakai login
        $pw = session('import_result')['passwords']['guru.baru'];
        $this->post('/logout');
        $this->post('/login', ['username' => 'guru.baru', 'password' => $pw])->assertRedirect('/');
    }

    public function test_import_pairings(): void
    {
        $c = SchoolClass::orderBy('id')->get();
        $s = Subject::regular()->orderBy('id')->get();
        $u = $this->guru->username;
        $this->run_('pasangan', [['Username', 'Mapel', 'Kelas'], [$u, $s[0]->name, $c[1]->name], [$u, $s[0]->name, $c[1]->name], ['hantu', $s[0]->name, $c[0]->name], [$u, 'Nol', $c[0]->name]])
            ->assertSessionHas('import_result', fn ($r) => $r['ok'] === 1 && count($r['skip']) === 3);
        $this->assertTrue(Pairing::where(['user_id' => $this->guru->id, 'subject_id' => $s[0]->id, 'class_id' => $c[1]->id])->exists());
    }

    public function test_missing_column_and_wrong_file_type_are_rejected(): void
    {
        $this->run_('kelas', [['Warna'], ['Biru']])->assertSessionHas('error');
        $this->actingAs($this->admin)->post('/admin/impor/kelas', ['file' => UploadedFile::fake()->create('x.exe', 5)])->assertSessionHasErrors('file');
    }
}
