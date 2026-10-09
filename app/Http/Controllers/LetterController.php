<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Audit;
use App\Services\Rules;
use App\Support\LetterForms;
use Illuminate\Http\Request;

/** Surat resmi siap cetak (Ctrl+P → Simpan sebagai PDF), pengganti Google Docs. */
class LetterController extends Controller
{
    private function authorizeStudent(Student $s): void
    {
        $u = $this->me();
        abort_unless($u->hasRole('admin', 'superadmin', 'bk', 'kepsek') || $u->isWaliOf($s->class_id), 403, 'Surat hanya untuk Wali Kelas siswa, Guru BK, Kepala Sekolah, atau Administrator.');
    }

    public function warning(Request $request, Student $student)
    {
        return $this->form($request, 'peringatan', $student);
    }

    public function summons(Request $request, Student $student)
    {
        return $this->form($request, 'panggilan', $student);
    }

    /** Surat siswa bentuk resmi SMK IBU: panggilan, peringatan, teguran, pernyataan, berita acara, izin. */
    public function form(Request $request, string $jenis, Student $student)
    {
        $this->authorizeStudent($student);
        $title = LetterForms::TITLES[$jenis] ?? abort(404);
        Audit::log('Cetak '.$title, 'Siswa', $this->me()->nama, "{$student->nama} ({$student->nis})");
        $fields = LetterForms::fields($jenis, $student, $this->me(), $request);
        $v = collect($fields)->mapWithKeys(fn ($f) => [$f['k'] => $f['v']])->all();

        return view('letters.form', [
            'jenis' => $jenis, 'title' => $title, 'student' => $student->load('schoolClass'), 'fields' => $fields, 'v' => $v,
            'now' => LetterForms::dateParts(), 'cfg' => config('absensi.surat'),
        ]);
    }

    public function report(Request $request)
    {
        $class = SchoolClass::findOrFail((int) $request->query('class'));
        $subject = Subject::findOrFail((int) $request->query('subject'));
        $u = $this->me();
        abort_unless(Rules::teacherAuthorization($u, $subject->id, $class->id)['allowed'] || $u->hasRole('bk', 'kepsek') || $u->isWaliOf($class->id), 403);
        $students = Student::active()->where('class_id', $class->id)->orderBy('nama')->get();
        $records = Attendance::query()->scope($class->id, $subject->id)->get();
        $acts = GradeActivity::where(['class_id' => $class->id, 'subject_id' => $subject->id, 'tipe_skala' => 'angka'])->pluck('id');
        $vals = GradeValue::whereIn('activity_id', $acts)->get()->groupBy('student_id');
        $rows = $students->map(function ($s) use ($records, $vals) {
            $sr = $records->where('student_id', $s->id);
            $nums = ($vals[$s->id] ?? collect())->pluck('nilai')->filter(fn ($v) => is_numeric($v));

            return ['s' => $s, 'rate' => $sr->count() ? (int) round($sr->where('status', 'H')->count() / $sr->count() * 100) : 100,
                'avg' => $nums->count() ? number_format($nums->avg(), 1) : '-'];
        });
        $tot = $records->count();

        return view('letters.report', [
            'class' => $class, 'subject' => $subject, 'rows' => $rows, 'sessions' => $records->pluck('tanggal')->unique()->count(),
            'pct' => $tot ? number_format($records->where('status', 'H')->count() / $tot * 100, 1) : '100',
        ]);
    }
}
