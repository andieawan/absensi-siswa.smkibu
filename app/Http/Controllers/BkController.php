<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\DelegationToken;
use App\Services\AccessService;
use App\Services\AttendanceService;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Integrasi BK: input absensi manual BK + rekap ketidakhadiran per kelas. */
class BkController extends Controller
{
    public function index(Request $request)
    {
        $classes = SchoolClass::orderBy('name')->get();
        $classId = (int) ($request->query('class') ?: $classes->first()?->id);
        $students = Student::active()->where('class_id', $classId)->orderBy('nama')->get();
        $cnt = [];
        foreach (Attendance::where('class_id', $classId)->get(['student_id', 'status']) as $r) {
            $cnt[$r->student_id][$r->status] = ($cnt[$r->student_id][$r->status] ?? 0) + 1;
        }
        $recap = $students->map(function ($s) use ($cnt) {
            $c = $cnt[$s->id] ?? [];

            return ['s' => $s, 'h' => $c['H'] ?? 0, 'i' => $c['I'] ?? 0, 'sk' => $c['S'] ?? 0, 'a' => $c['A'] ?? 0,
                'abs' => ($c['I'] ?? 0) + ($c['S'] ?? 0) + ($c['A'] ?? 0)];
        })->sortByDesc('abs')->values();

        $tokens = DelegationToken::where('class_id', $classId)->where('status', 'aktif')->where('expires_at_millis', '>', Dates::nowMillis())->orderByDesc('created_at')->get();

        return view('bk.index', compact('classes', 'classId', 'students', 'recap', 'tokens') + ['newToken' => session('new_token')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student' => 'required|integer|exists:students,id', 'status' => 'required|in:H,I,S,A',
            'date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:200',
        ], [], ['student' => 'siswa']);
        $s = Student::findOrFail($data['student']);
        $r = AttendanceService::submit($this->me(), $s->class_id, null, $data['date'], [['student_id' => $s->id, 'status' => $data['status'], 'notes' => $data['notes'] ?? '']], 'bk_manual');

        return redirect()->route('bk', ['class' => $s->class_id])->with('success', "Absensi manual BK untuk {$s->nama} tersimpan (".($r['created'] ? 'baru' : 'diperbarui').').');
    }

    /** Guru BK dapat membuat tautan delegasi ketua kelas untuk kelas mana pun. */
    public function delegate(Request $request)
    {
        $data = $request->validate(['class' => 'required|integer', 'hours' => 'nullable|integer']);
        $t = AccessService::createDelegation($this->me(), (int) $data['class'], (int) ($data['hours'] ?? 24));

        return redirect()->route('bk', ['class' => $data['class']])->with('new_token', $t->token)->with('success', 'Tautan delegasi ketua kelas dibuat.');
    }

    public function revoke(DelegationToken $token)
    {
        AccessService::revokeDelegation($this->me(), $token);

        return redirect()->route('bk', ['class' => $token->class_id])->with('success', 'Tautan delegasi dicabut.');
    }
}
