<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\ClassBoardToken;
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
        $get = $r->isMethod('get'); // hanya tampilan (GET) yang dikoreksi; simpan/hapus (POST) dipakai apa adanya agar ditolak oleh otorisasi
        $subjects = Assignments::subjects($u);
        $classMap = [];
        $subjectId = null;
        if ($mode === 'mapel') {
            // Guru memilih MAPEL dulu (jumlahnya sedikit), lalu kelas yang diajarnya untuk mapel itu.
            $want = (int) $r->input('subject');
            $subjectId = $want && (! $get || $subjects->contains('id', $want)) ? $want : (int) $subjects->first()?->id;
            $classes = Assignments::classesForSubject($u, $subjectId);
            $classMap = Assignments::classMap($u, $subjects);
        } else {
            $classes = Assignments::classes($u, true);
        }
        $want = (int) $r->input('class');
        $classId = $want && (! $get || $classes->contains('id', $want)) ? $want : (int) $classes->first()?->id;
        if ($mode === 'wali' && $isWali && ! $u->isAdmin()) {
            $classId = $u->kelas_wali_id;
        }
        $tanggal = Dates::valid($r->input('date')) ? $r->input('date') : Dates::today();

        return compact('mode', 'classes', 'subjects', 'classMap', 'classId', 'subjectId', 'tanggal', 'isWali');
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
            $data['activeTokens'] = DelegationToken::where('class_id', $c['classId'])->where('status', 'aktif')->where('expires_at_millis', '>', Dates::nowMillis())->orderByDesc('created_at')->get();
            $data['newToken'] = session('new_token');
            $data['boardReady'] = \App\Support\DbUpdate::boardReady();
            $data['board'] = $data['boardReady'] && $c['classId'] ? ClassBoardToken::where('class_id', $c['classId'])->where('status', 'aktif')->first() : null;
            $data['boardUrl'] = $data['board'] ? route('board', $data['board']->token) : null;
            $data['boardMsg'] = $data['board'] ? "Assalamu'alaikum Bapak/Ibu wali murid kelas ".($data['class']->name ?? '').'. Info siswa yang tidak masuk sekolah setiap hari dapat dilihat di tautan berikut: '.$data['boardUrl']."\n\n".\App\Models\SchoolSetting::current()->school_name : null;
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

    public function revoke(Request $request, DelegationToken $token)
    {
        $c = $this->context($request);
        AccessService::revokeDelegation($this->me(), $token);

        return $this->back($c, ['tab' => 'riwayat'])->with('success', 'Tautan delegasi dicabut. Ketua kelas tidak bisa memakainya lagi.');
    }

    public function delegate(Request $request)
    {
        $c = $this->context($request);
        $t = AccessService::createDelegation($this->me(), $c['classId'], (int) $request->input('hours', 24));

        return $this->back($c, ['tab' => 'riwayat'])->with('new_token', $t->token)->with('success', 'Tautan delegasi dibuat.');
    }

    public function boardCreate(Request $request)
    {
        $c = $this->context($request);
        if (! \App\Support\DbUpdate::boardReady()) {
            return $this->back($c, ['tab' => 'riwayat'])->with('error', 'Fitur ini butuh pembaruan database: Admin → Pengaturan → Perbarui Database Sekarang.');
        }
        AccessService::ensureBoardToken($this->me(), (int) $c['classId']);

        return $this->back($c, ['tab' => 'riwayat'])->with('success', 'Tautan info kehadiran kelas siap dibagikan ke wali murid.');
    }

    public function boardRevoke(Request $request, string $token)
    {
        $c = $this->context($request);
        AccessService::revokeBoardToken($this->me(), ClassBoardToken::findOrFail($token));

        return $this->back($c, ['tab' => 'riwayat'])->with('success', 'Tautan dicabut. Wali murid tidak bisa lagi membukanya; buat tautan baru bila perlu.');
    }
}
