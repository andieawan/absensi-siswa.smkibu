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

    /** Info kehadiran kelas untuk wali murid: form verifikasi (NIS + 4 digit akhir HP orang tua terdaftar). */
    public function board(string $token)
    {
        $t = $this->boardToken($token);

        return $t instanceof \Illuminate\Http\Response ? $t : view('public.board', $this->boardBase($t) + ['error' => null, 'student' => null, 'rows' => collect(), 'fail' => null]);
    }

    /** Verifikasi lalu tampilkan hanya kehadiran anak itu (14 hari terakhir, absen harian). */
    public function boardCheck(\Illuminate\Http\Request $request, string $token)
    {
        $t = $this->boardToken($token);
        if ($t instanceof \Illuminate\Http\Response) {
            return $t;
        }
        $nis = trim((string) $request->input('nis'));
        $pin = preg_replace('/\D+/', '', (string) $request->input('pin')) ?? '';
        $student = $nis !== '' ? \App\Models\Student::active()->where('class_id', $t->class_id)->where('nis', $nis)->first() : null;
        $phone = $student ? \App\Support\WhatsApp::normalize($student->telp_ortu) : null;
        // Pesan gagal sengaja sama untuk NIS salah / nomor belum terdaftar / PIN salah (tidak membocorkan data).
        if (! $student || ! $phone || strlen($pin) !== 4 || ! hash_equals(substr($phone, -4), $pin)) {
            return view('public.board', $this->boardBase($t) + ['error' => null, 'student' => null, 'rows' => collect(), 'nis' => $nis,
                'fail' => 'NIS atau 4 digit nomor HP tidak cocok. Pastikan memakai nomor HP orang tua yang terdaftar di sekolah; bila belum terdaftar, hubungi wali kelas.']);
        }
        $from = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', \App\Support\Dates::today())->subDays(13)->format('Y-m-d');
        $rows = \App\Models\Attendance::query()->scope($t->class_id, null)->where('student_id', $student->id)->where('tanggal', '>=', $from)->orderByDesc('tanggal')->get();

        return view('public.board', $this->boardBase($t) + ['error' => null, 'student' => $student, 'rows' => $rows, 'fail' => null, 'today' => \App\Support\Dates::today()]);
    }

    private function boardToken(string $token)
    {
        $t = \App\Support\DbUpdate::boardReady() ? \App\Models\ClassBoardToken::find($token) : null;
        if (! $t || $t->status !== 'aktif') {
            return response()->view('public.board', ['error' => 'Tautan info kehadiran tidak ditemukan atau sudah dicabut oleh wali kelas.'], 404);
        }

        return $t;
    }

    private function boardBase($t): array
    {
        $class = \App\Models\SchoolClass::find($t->class_id);

        return ['class' => $class, 'wali' => $class ? \App\Models\User::where('kelas_wali_id', $class->id)->first() : null, 'token' => $t->token, 'nis' => '', 'today' => \App\Support\Dates::today()];
    }
}
