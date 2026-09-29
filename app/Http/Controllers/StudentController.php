<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\ParentToken;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AccessService;
use App\Services\Rules;
use Illuminate\Http\Request;

/** Riwayat Siswa (Student 360). */
class StudentController extends Controller
{
    public function index(Request $request)
    {
        $u = $this->me();
        $q = trim((string) $request->query('q'));
        $fc = (int) $request->query('class');
        $list = Student::with('schoolClass')->when($fc, fn ($x) => $x->where('class_id', $fc))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('nama', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")))
            ->orderBy('nama')->paginate(60)->withQueryString();

        $data = ['classes' => SchoolClass::orderBy('name')->get(), 'list' => $list, 'q' => $q, 'fc' => $fc];
        $student = $request->query('id') ? Student::with('schoolClass')->find((int) $request->query('id')) : null;
        if ($student) {
            $myGrades = GradeValue::where('student_id', $student->id)->get();
            $acts = GradeActivity::whereIn('id', $myGrades->pluck('activity_id'))->get()->keyBy('id');
            $data += [
                'student' => $student,
                'stats' => Rules::attendanceStats($student->id),
                'attention' => Rules::attentionCategory($student->id),
                'patterns' => Rules::periodicPattern($student->id),
                'absences' => Attendance::where('student_id', $student->id)->where('status', '!=', 'H')->orderByDesc('tanggal')->get(),
                'subjectNames' => Subject::pluck('name', 'id'),
                'acts' => $acts,
                'grades' => $myGrades->filter(fn ($g) => isset($acts[$g->activity_id]))
                    ->sortByDesc(fn ($g) => $acts[$g->activity_id]->tanggal_kegiatan),
                'canParent' => AccessService::canManageParentAccess($u, $student),
                'canLetter' => $u->hasRole('admin', 'superadmin', 'bk', 'kepsek') || $u->isWaliOf($student->class_id),
                'canOverride' => $u->hasRole('admin', 'superadmin', 'kepsek'),
                'tokens' => ParentToken::where('student_id', $student->id)->orderByDesc('created_at')->get(),
                'newToken' => session('new_token'),
                'bkRecords' => \App\Services\BkService::canOpen($u)
                    ? \App\Services\BkService::visible($u)->where('student_id', $student->id)->orderByDesc('tanggal')->orderByDesc('id')->limit(30)->get()
                    : null,
            ];
        }

        return view('students.index', $data + ['student' => null]);
    }

    public function clearance(Request $request, Student $student)
    {
        $r = AccessService::clearance($this->me(), $student, $request->boolean('override'), (string) $request->input('reason'));

        return redirect()->route('students', ['id' => $student->id])
            ->with('success', "Pengesahan akademik DISETUJUI. Kode: {$r['code']} · kehadiran {$r['rate']}%".($r['override'] ? ' (dengan dispensasi)' : '').'.');
    }

    public function parentCreate(Student $student)
    {
        $t = AccessService::createParentToken($this->me(), $student);

        return redirect()->route('students', ['id' => $student->id])->with('new_token', $t->token)->with('success', 'Tautan Portal Wali Murid dibuat.');
    }

    public function parentRevoke(string $token)
    {
        $t = ParentToken::findOrFail($token);
        AccessService::revokeParentToken($this->me(), $t);

        return redirect()->route('students', ['id' => $t->student_id])->with('success', 'Akses wali murid dicabut.');
    }
}
