<?php

namespace App\Http\Controllers;

use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\Student;
use App\Services\Assignments;
use App\Services\GradeService;
use App\Services\Rules;
use App\Support\Dates;
use Illuminate\Http\Request;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $u = $this->me();
        $classes = Assignments::classes($u, false);
        $subjects = Assignments::subjects($u);
        $classId = (int) ($request->query('class') ?: $classes->first()?->id);
        $subjectId = (int) ($request->query('subject') ?: $subjects->first()?->id);
        $tab = in_array($request->query('tab'), ['aktivitas', 'rekap'], true) ? $request->query('tab') : 'input';

        $students = Student::active()->where('class_id', $classId)->orderBy('nama')->get();
        $acts = GradeActivity::where(['class_id' => $classId, 'subject_id' => $subjectId])->orderByDesc('tanggal_kegiatan')->orderByDesc('created_at')->get();
        $vals = [];
        foreach (GradeValue::whereIn('activity_id', $acts->pluck('id'))->get() as $v) {
            $vals[$v->activity_id][$v->student_id] = $v->nilai;
        }
        $editing = $request->query('act') ? $acts->firstWhere('id', $request->query('act')) : null;
        if ($editing && ! Rules::gradeEditable($u, $editing)) {
            $editing = null;
            session()->now('warning', 'Kegiatan itu diinput lebih dari 7 hari lalu dan sudah dikunci.');
        }
        $auth = $classId && $subjectId ? Rules::teacherAuthorization($u, $subjectId, $classId) : ['allowed' => false, 'error' => 'Pilih kelas dan mata pelajaran.'];

        return view('grades.index', compact('classes', 'subjects', 'classId', 'subjectId', 'tab', 'students', 'acts', 'vals', 'editing', 'auth') + ['today' => Dates::today()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'class' => 'required|integer', 'subject' => 'required|integer', 'act' => 'nullable|string|max:64',
            'nama' => 'required|string|max:120', 'tanggal' => 'required|date_format:Y-m-d', 'tipe' => 'required|in:angka,huruf',
            'nilai' => 'required|array',
        ], [], ['nama' => 'nama kegiatan', 'nilai' => 'nilai siswa']);
        GradeService::save($this->me(), ($data['act'] ?? null) ?: null, (int) $data['class'], (int) $data['subject'], $data['nama'], $data['tanggal'], $data['tipe'], $data['nilai']);

        return redirect()->route('grades', ['class' => $data['class'], 'subject' => $data['subject'], 'tab' => 'aktivitas'])
            ->with('success', 'Penilaian "'.$data['nama'].'" berhasil disimpan untuk '.count($data['nilai']).' siswa.');
    }

    public function destroy(GradeActivity $activity)
    {
        GradeService::delete($this->me(), $activity);

        return redirect()->route('grades', ['class' => $activity->class_id, 'subject' => $activity->subject_id, 'tab' => 'aktivitas'])->with('success', 'Kegiatan penilaian dihapus.');
    }
}
