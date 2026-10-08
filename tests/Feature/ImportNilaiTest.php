<?php

namespace Tests\Feature;

use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Impor riwayat nilai format aplikasi lama (DataNilai = JSON {"NIS":"nilai"}). */
class ImportNilaiTest extends TestCase
{
    private const HDR = ['Timestamp', 'Nama Guru', 'Mapel', 'Kelas', 'KegiatanId', 'NamaKegiatan', 'TanggalKegiatan', 'TipeSkala', 'DataNilai'];

    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nl').'.xlsx';
        file_put_contents($path, Xlsx::build($sheets));

        return new UploadedFile($path, 'nilai.xlsx', null, null, true);
    }

    private function imp(UploadedFile $f, bool $dry = false)
    {
        return $this->actingAs($this->admin)->post('/admin/impor/nilai', ['file' => $f] + ($dry ? ['dry' => 1] : []));
    }

    private function row(string $id, string $tipe, array $data, array $over = []): array
    {
        return array_replace(['01/10/2026 9:00:00', 'Guru', 'Matematika', 'X RPL 1', $id, 'Tugas 1', $this->daysAgo(5), $tipe, json_encode($data)], $over);
    }

    public function test_imports_activities_values_and_ignores_wrong_sheet_headers(): void
    {
        $wrongHdr = ['Timestamp', 'Nama Guru', 'Mata Pelajaran', 'Kelas', 'Tanggal', 'Hadir', 'Izin', 'Sakit', 'Alpa']; // judul keliru seperti di sheet lama
        $this->imp($this->xlsx([
            'A' => [self::HDR, $this->row('id-1', 'angka', ['N-1' => '80', 'N-2' => '65', 'N-3' => '0'])],
            'B' => [$wrongHdr, $this->row('id-2', 'huruf', ['N-1' => 'a', 'N-2' => 'D'])],
        ]));
        $this->assertSame(2, session('import_result')['ok']);
        $this->assertSame([], session('import_result')['skip']);
        $a = GradeActivity::findOrFail('id-1');
        $this->assertSame([$this->guru->id, 2, 1, 'angka'], [$a->teacher_id, $a->subject_id, $a->class_id, $a->tipe_skala]);
        $this->assertSame(3, GradeValue::where('activity_id', 'id-1')->count());
        $this->assertSame('0', GradeValue::where('activity_id', 'id-1')->where('student_id', 3)->value('nilai'));
        $this->assertSame('A', GradeValue::where('activity_id', 'id-2')->where('student_id', 1)->value('nilai')); // huruf dinormalkan
    }

    public function test_dry_run_idempotent_and_existing_id_skipped(): void
    {
        $file = fn () => $this->xlsx(['A' => [self::HDR, $this->row('id-9', 'angka', ['N-1' => '90'])]]);
        $this->imp($file(), true);
        $this->assertSame(0, GradeActivity::count());
        $this->imp($file());
        $this->assertSame(1, GradeActivity::count());
        $this->imp($file());
        $this->assertSame(1, GradeActivity::count());
        $this->assertTrue(collect(session('import_result')['skip'])->contains(fn ($m) => str_contains($m, 'sudah ada')));
    }

    public function test_bad_values_and_rows_are_reported(): void
    {
        $this->imp($this->xlsx(['A' => [self::HDR,
            $this->row('b1', 'angka', ['N-1' => '101', 'N-2' => 'abc', 'N-3' => '70', 'N-999' => '80', 'N-5' => '60']), // N-5 kelas lain
            $this->row('b2', 'huruf', ['N-1' => 'Z']),                       // tak ada nilai valid
            $this->row('b3', 'skala', ['N-1' => '1']),                       // tipe salah
            $this->row('b4', 'angka', ['N-1' => '1'], [2 => 'Mapel Hantu']),  // mapel tak ada
            ['', 'G', 'Matematika', 'X RPL 1', 'b5', 'Tugas', $this->daysAgo(1), 'angka', 'bukan json'],
        ]]));
        $res = session('import_result');
        $this->assertSame(1, $res['ok']);
        $this->assertSame(1, GradeValue::where('activity_id', 'b1')->count()); // hanya N-3
        $this->assertNull(GradeActivity::find('b2'));
        $txt = implode("\n", $res['skip']);
        foreach (['101', 'abc', 'N-999', 'N-5', "'Z'", 'skala', 'Mapel Hantu', 'JSON'] as $needle) {
            $this->assertStringContainsString($needle, $txt);
        }
    }

    public function test_page_template_and_permission(): void
    {
        $this->actingAs($this->admin)->get('/admin/impor')->assertOk()->assertSee('Riwayat Nilai');
        $this->actingAs($this->admin)->get('/admin/impor/nilai/template')->assertOk();
        $this->actingAs($this->wali)->post('/admin/impor/nilai', ['file' => UploadedFile::fake()->create('x.csv', 1)])->assertForbidden();
    }
}
