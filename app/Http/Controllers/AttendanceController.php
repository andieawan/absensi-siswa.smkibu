<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\DelegationToken;
use App\Models\Student;
use App\Services\AccessService;
use App\Services\Assignments;
use App\Services\AttendanceService;
use App\Services\Rules;
use App\Support\Dates;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /** Konteks form (mode, kelas, mapel, tanggal) dari query/body. */
    private function context(Request $r): array
    {
        $u = $this->me();
        $isWali = $u->kelas_wali_id !== null;
        $mode = in_array($r->input('mode'), ['wali', 'mapel'], true) ? $r->input('mode') : ($isWali ? 'wali' : 'mapel');
        $classes = Assignments::classes($u, $mode === 'wali');
        $subjects = Assignments::subjects($u);
        $classId = (int) ($r->input('class') ?: $classes->first()?->id);
        if ($mode === 'wali' && $isWali && ! $u->isAdmin()) {
            $classId = $u->kelas_wali_id;
        }
        $subjectId = $mode === 'mapel' ? (int) ($r->input('subject') ?: $subjects->first()?->id) : null;
        $tanggal = Dates::valid($r->input('date')) ? $r->input('date') : Dates::today();

        return compact('mode', 'classes', 'subjects', 'classId', 'subjectId', 'tanggal', 'isWali');
    }

    private function back(array $c, array $extra = [])
    {
        return redirect()->route('attendance', array_filter([
            'mode' => $c['mode'], 'class' => $c['classId'], 'subject' => $c['subjectId'], 'date' => $c['tanggal'],
        ] + $extra));
    }

    public function index(Request $request)
    {
        $c = $this->context($request);
        $u = $this->me();
        $tab = $request->query('tab') === 'riwayat' ? 'riwayat' : 'input';
        $auth = $c['classId'] ? Rules::teacherAuthorization($u, $c['subjectId'], $c['classId']) : ['allowed' => false, 'error' => 'Belum ada kelas yang bisa dipilih.'];

        $data = $c + ['tab' => $tab, 'auth' => $auth, 'class' => $c['classes']->firstWhere('id', $c['classId'])];
        if ($tab === 'input') {
            $data['students'] = Student::active()->where('class_id', $c['classId'])->orderBy('nama')->get();
            $data['existing'] = Attendance::query()->scope($c['classId'], $c['subjectId'])->where('tanggal', $c['tanggal'])->get()->keyBy('student_id');
        } else {
            $sessions = [];
            foreach (Attendance::query()->scope($c['classId'], $c['subjectId'])->get(['tanggal', 'status', 'recorded_via']) as $r) {
                $sessions[$r->tanggal] ??= ['tanggal' => $r->tanggal, 'H' => 0, 'I' => 0, 'S' => 0, 'A' => 0, 'via' => $r->recorded_via];
                $sessions[$r->tanggal][$r->status]++;
            }
            krsort($sessions);
            $data['sessions'] = $sessions;
            $data['activeTokens'] = DelegationToken::where('class_id', $c['classId'])->where('status', 'aktif')->where('expires_at_millis', '>', Dates::nowMillis())->count();
            $data['newToken'] = session('new_token');
        }

        return view('attendance.index', $data);
    }

    public function store(Request $request)
    {
        $c = $this->context($request);
        $r = AttendanceService::submit($this->me(), $c['classId'], $c['subjectId'], $c['tanggal'], PublicController::entries($request), $c['mode'] === 'wali' ? 'wali' : 'guru');
        $msg = "Presensi tersimpan ({$r['created']} baru, {$r['updated']} diperbarui).";
        if ($r['alerts']) {
            $msg .= ' Peringatan 85%: '.implode('; ', array_slice($r['alerts'], 0, 5)).(count($r['alerts']) > 5 ? '; …' : '');
        }

        return $this->back($c)->with($r['alerts'] ? 'warning' : 'success', $msg);
    }

    public function destroy(Request $request)
    {
        $c = $this->context($request);
        $tgl = (string) $request->input('tgl');
        $n = AttendanceService::deleteSession($this->me(), $c['classId'], $c['subjectId'], $tgl);

        return $this->back($c, ['tab' => 'riwayat'])->with('success', "Sesi $tgl dihapus ($n data).");
    }

    public function delegate(Request $request)
    {
        $c = $this->context($request);
        $t = AccessService::createDelegation($this->me(), $c['classId'], (int) $request->input('hours', 24));

        return $this->back($c, ['tab' => 'riwayat'])->with('new_token', $t->token)->with('success', 'Tautan delegasi dibuat.');
    }
}
