<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Impor riwayat absensi format aplikasi lama (Hadir/Izin/Sakit/Alpa berisi NIS dipisah koma). */
class ImportAbsensiTest extends TestCase
{
    private const HDR = ['Timestamp', 'Nama Guru', 'Mata Pelajaran', 'Kelas', 'Tanggal', 'Hadir', 'Izin', 'Sakit', 'Alpa'];

    private function csv(array $rows): UploadedFile
    {
        $lines = [implode(',', self::HDR)];
        foreach ($rows as $r) {
            $lines[] = implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $r));
        }

        return UploadedFile::fake()->createWithContent('absen.csv', implode("\n", $lines));
    }

    private function imp(UploadedFile $f, bool $dry = false)
    {
        return $this->actingAs($this->admin)->post('/admin/impor/absensi', ['file' => $f] + ($dry ? ['dry' => 1] : []));
    }

    public function test_imports_sessions_with_statuses_and_teacher(): void
    {
        $d1 = $this->daysAgo(10);
        $d2 = date('d/m/Y', Dates::parse($this->daysAgo(9)));
        $this->imp($this->csv([
            ['', 'GURU', 'Matematika', 'X RPL 1', $d1, 'N-1, N-2', 'N-3', '', 'N-4'],
            ['', 'Impor Manual (Hard Copy)', '', 'x  rpl 1', $d2, 'N-1', '', 'N-2', ''],
        ]))->assertSessionHas('import_result');
        $this->assertSame(2, session('import_result')['ok']);
        $this->assertSame(4, Attendance::where('tanggal', $d1)->whereNotNull('subject_id')->count());
        $this->assertSame('A', Attendance::where('tanggal', $d1)->where('student_id', 4)->value('status'));
        $a = Attendance::where('tanggal', $d1)->where('student_id', 1)->first();
        $this->assertSame($this->guru->id, $a->recorded_by);
        $this->assertSame('guru', $a->recorded_via);
        $h = Attendance::where('tanggal', $this->daysAgo(9))->whereNull('subject_id')->get();
        $this->assertSame(2, $h->count());
        $this->assertSame('upload_hardcopy', $h->first()->recorded_via);
        $this->assertSame($this->admin->id, $h->first()->recorded_by);
    }

    public function test_dry_run_saves_nothing_and_existing_records_are_not_overwritten(): void
    {
        $d = $this->daysAgo(5);
        $row = ['', 'Guru', 'Matematika', 'X RPL 1', $d, 'N-1', '', '', 'N-2'];
        $this->imp($this->csv([$row]), true);
        $this->assertSame(0, Attendance::count());

        $this->mark(1, $d, 'S', 2); // sudah ada: Sakit (mapel Matematika)
        $this->imp($this->csv([$row]));
        $this->assertSame('S', Attendance::where('student_id', 1)->where('tanggal', $d)->value('status'));
        $this->assertSame('A', Attendance::where('student_id', 2)->where('tanggal', $d)->value('status'));
        $this->assertTrue(collect(session('import_result')['skip'])->contains(fn ($m) => str_contains($m, 'tidak ditimpa')));
        $this->imp($this->csv([$row])); // ulang: tidak menambah apa pun
        $this->assertSame(2, Attendance::count());
    }

    public function test_bad_rows_are_reported_and_good_ones_still_imported(): void
    {
        $d = $this->daysAgo(3);
        $this->imp($this->csv([
            ['', 'G', 'Matematika', 'Kelas Hantu', $d, 'N-1', '', '', ''],
            ['', 'G', 'Mapel Hantu', 'X RPL 1', $d, 'N-1', '', '', ''],
            ['', 'G', 'Matematika', 'X RPL 1', 'bukan-tanggal', 'N-1', '', '', ''],
            ['', 'G', 'Matematika', 'X RPL 1', date('Y-m-d', strtotime('+5 days')), 'N-1', '', '', ''],
            ['', 'G', 'Matematika', 'X RPL 1', $d, 'N-1, N-5, N-999', 'N-1', '', ''],
            ['', 'G', 'Matematika', 'X RPL 1', $this->daysAgo(2), 'N-3', '', '', ''],
        ]));
        $res = session('import_result');
        $this->assertSame(1, $res['ok']); // hanya baris terakhir; baris 5 tak punya siswa valid (N-1 ganda ambigu, N-5/N-999 bukan kelas ini)
        $this->assertSame(1, Attendance::where('tanggal', $this->daysAgo(2))->count());
        $this->assertSame(0, Attendance::where('tanggal', $d)->count());
        $txt = implode("\n", $res['skip']);
        foreach (['Kelas Hantu', 'Mapel Hantu', 'bukan-tanggal', 'masa depan', 'N-999', 'lebih dari satu status'] as $needle) {
            $this->assertStringContainsString($needle, $txt);
        }
    }

    public function test_xlsx_with_many_sheets_and_reordered_columns(): void
    {
        $d = $this->daysAgo(4);
        $hdr2 = ['Kelas', 'Tanggal', 'Mata Pelajaran', 'Alpa', 'Sakit', 'Izin', 'Hadir', 'Nama Guru'];
        $bin = Xlsx::build([
            'XI_A' => [self::HDR, ['', 'Guru', 'Matematika', 'X RPL 1', $d, 'N-1', '', '', '']],
            'XI_B' => [$hdr2, ['X TKJ 1', $d, 'Matematika', 'N-6', '', '', 'N-5', 'Guru']],
            'Petunjuk' => [['bukan data']],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'ab').'.xlsx';
        file_put_contents($path, $bin);
        $this->imp(new UploadedFile($path, 'absen.xlsx', null, null, true));
        $this->assertSame(2, session('import_result')['ok']);
        $this->assertSame('H', Attendance::where('student_id', 5)->value('status'));
        $this->assertSame('A', Attendance::where('student_id', 6)->value('status'));
        @unlink($path);
    }

    public function test_template_and_page(): void
    {
        $this->actingAs($this->admin)->get('/admin/impor')->assertOk()->assertSee('Riwayat Absensi');
        $this->actingAs($this->admin)->get('/admin/impor/absensi/template')->assertOk();
        $this->actingAs($this->wali)->post('/admin/impor/absensi', ['file' => $this->csv([])])->assertForbidden();
    }
}
