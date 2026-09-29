<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Rules;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Unduhan Excel (.xlsx) — pengganti ekspor Google Sheets. */
class ExportController extends Controller
{
    private function clean(string $s): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_');
    }

    private function mayView(?int $subjectId, int $classId): bool
    {
        $u = $this->me();

        return $u->hasRole('admin', 'superadmin', 'kepsek', 'bk') || Rules::teacherAuthorization($u, $subjectId, $classId)['allowed'];
    }

    public function attendance(Request $request)
    {
        $class = SchoolClass::findOrFail((int) $request->query('class'));
        $subjectId = $request->filled('subject') ? (int) $request->query('subject') : null;
        abort_unless($this->mayView($subjectId, $class->id), 403);
        $records = Attendance::query()->scope($class->id, $subjectId)->get(['student_id', 'tanggal', 'status']);
        $dates = $records->pluck('tanggal')->unique()->sort()->values()->all();
        $map = [];
        foreach ($records as $r) {
            $map[$r->student_id][$r->tanggal] = $r->status;
        }
        $rows = [array_merge(['No', 'NIS', 'Nama Siswa', 'JK'], $dates, ['Hadir (H)', 'Izin (I)', 'Sakit (S)', 'Alpa (A)', '% Kehadiran'])];
        foreach (Student::where('class_id', $class->id)->orderBy('nama')->get() as $i => $s) {
            $c = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
            $line = [$i + 1, $s->nis, $s->nama, $s->jk];
            foreach ($dates as $d) {
                $st = $map[$s->id][$d] ?? '-';
                isset($c[$st]) && $c[$st]++;
                $line[] = $st;
            }
            $rows[] = array_merge($line, [$c['H'], $c['I'], $c['S'], $c['A'], ($dates ? (int) round($c['H'] / count($dates) * 100) : 0).'%']);
        }
        $subj = $subjectId ? (Subject::find($subjectId)->name ?? 'Mapel') : 'Harian';

        return Xlsx::download('Rekap_Absensi_'.$this->clean($class->name).'_'.$this->clean($subj).'_'.Dates::today().'.xlsx', ['Rekap Absensi' => $rows]);
    }

    public function grades(Request $request)
    {
        $class = SchoolClass::findOrFail((int) $request->query('class'));
        $subject = Subject::findOrFail((int) $request->query('subject'));
        abort_unless($this->mayView($subject->id, $class->id), 403);
        $acts = GradeActivity::where(['class_id' => $class->id, 'subject_id' => $subject->id])->orderBy('tanggal_kegiatan')->get();
        $vals = [];
        foreach (GradeValue::whereIn('activity_id', $acts->pluck('id'))->get() as $v) {
            $vals[$v->activity_id][$v->student_id] = $v->nilai;
        }
        $rows = [array_merge(['No', 'NIS', 'Nama Siswa'], $acts->pluck('nama_kegiatan')->all(), ['Rata-Rata Angka'])];
        foreach (Student::active()->where('class_id', $class->id)->orderBy('nama')->get() as $i => $s) {
            $line = [$i + 1, $s->nis, $s->nama];
            $nums = [];
            foreach ($acts as $a) {
                $v = $vals[$a->id][$s->id] ?? '-';
                $line[] = $v;
                if ($a->tipe_skala === 'angka' && is_numeric($v)) {
                    $nums[] = (float) $v;
                }
            }
            $line[] = $nums ? round(array_sum($nums) / count($nums), 1) : '';
            $rows[] = $line;
        }

        return Xlsx::download('Rekap_Nilai_'.$this->clean($class->name).'_'.$this->clean($subject->name).'_'.Dates::today().'.xlsx', ['Rekap Nilai' => $rows]);
    }

    public function bk(Request $request)
    {
        $class = SchoolClass::findOrFail((int) $request->query('class'));
        $rows = [['No', 'NIS', 'Nama Siswa', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Total Absen', '% Kehadiran']];
        foreach (Student::active()->where('class_id', $class->id)->orderBy('nama')->get() as $i => $s) {
            $st = Rules::attendanceStats($s->id);
            $rows[] = [$i + 1, $s->nis, $s->nama, $st['hadir'], $st['izin'], $st['sakit'], $st['alpa'], $st['izin'] + $st['sakit'] + $st['alpa'], $st['rate'].'%'];
        }

        return Xlsx::download('Rekap_BK_'.$this->clean($class->name).'_'.Dates::today().'.xlsx', ['Rekap BK' => $rows]);
    }
}
