<?php

namespace App\Http\Controllers;

use App\Exceptions\UserError;
use App\Models\Attendance;
use App\Models\ParentToken;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AccessService;
use App\Services\Rules;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Halaman tanpa login: presensi Ketua Kelas (token kk_) dan Portal Wali Murid (token wm_). */
class PublicController extends Controller
{
    public function delegation(Request $request, string $token)
    {
        $tanggal = Dates::valid($request->query('date')) ? $request->query('date') : Dates::today();
        try {
            $t = AccessService::activeDelegation($token);
        } catch (UserError $e) {
            return response()->view('public.delegation', ['error' => $e->getMessage(), 't' => null], 410);
        }
        $students = Student::active()->where('class_id', $t->class_id)->orderBy('nama')->get();
        $existing = Attendance::query()->scope($t->class_id, null)->where('tanggal', $tanggal)->get()->keyBy('student_id');

        return view('public.delegation', [
            't' => $t, 'token' => $token, 'tanggal' => $tanggal, 'class' => SchoolClass::find($t->class_id),
            'students' => $students, 'existing' => $existing, 'error' => null,
        ]);
    }

    public function delegationSubmit(Request $request, string $token)
    {
        $tanggal = (string) $request->input('date');
        $entries = self::entries($request);
        $n = AccessService::submitDelegation($token, $tanggal, $entries);

        return redirect()->route('delegation', [$token, 'date' => $tanggal])->with('success', "Presensi tanggal $tanggal ($n siswa) berhasil disimpan. Terima kasih!");
    }

    public static function entries(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('status', []) as $sid => $st) {
            $out[] = ['student_id' => (int) $sid, 'status' => (string) $st, 'notes' => (string) ($request->input("notes.$sid") ?? '')];
        }

        return $out;
    }

    public function parent(string $token)
    {
        $t = ParentToken::find($token);
        $error = match (true) {
            ! $t => 'Tautan akses wali murid tidak ditemukan di sistem sekolah.',
            $t->status !== 'aktif' => 'Tautan akses ini sudah dicabut oleh wali kelas/Administrator.',
            default => null,
        };
        $student = $t && ! $error ? Student::with('schoolClass')->find($t->student_id) : null;
        if (! $error && ! $student) {
            $error = 'Data siswa untuk tautan ini tidak ditemukan.';
        }
        if ($error) {
            return response()->view('public.parent', ['error' => $error], 404);
        }

        return view('public.parent', [
            'error' => null, 'student' => $student, 'stats' => Rules::attendanceStats($student->id),
            'attention' => Rules::attentionCategory($student->id), 'patterns' => Rules::periodicPattern($student->id),
            'recent' => Attendance::where('student_id', $student->id)->orderByDesc('tanggal')->limit(30)->get(),
        ]);
    }
}
