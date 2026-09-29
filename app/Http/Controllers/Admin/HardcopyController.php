<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Upload absensi hardcopy: unduh template → unggah → pratinjau → simpan (jalur upload_hardcopy). */
class HardcopyController extends Controller
{
    public function index()
    {
        return view('admin.hardcopy', ['classes' => SchoolClass::orderBy('name')->get(), 'preview' => null, 'today' => Dates::today()]);
    }

    public function template(Request $request)
    {
        $class = SchoolClass::findOrFail((int) $request->query('class'));
        $tgl = Dates::valid($request->query('date')) ? $request->query('date') : Dates::today();
        $rows = [['No', 'NIS', 'Nama Siswa', 'JK', 'Status (H/I/S/A)', 'Catatan (Opsional)']];
        foreach (Student::active()->where('class_id', $class->id)->orderBy('nama')->get() as $i => $s) {
            $rows[] = [$i + 1, $s->nis, $s->nama, $s->jk, 'H', ''];
        }

        return Xlsx::download('Template_Absen_'.preg_replace('/\s+/', '_', $class->name)."_$tgl.xlsx", ['Template Absen' => $rows]);
    }

    public function preview(Request $request)
    {
        $data = $request->validate(['class_id' => 'required|integer|exists:classes,id', 'date' => 'required|date_format:Y-m-d', 'file' => 'required|file|max:5120']);
        $f = $request->file('file');
        [$hdr, $rows] = Xlsx::readWithHeader($f->getRealPath(), $f->getClientOriginalName());
        $iNis = Xlsx::col($hdr, ['nis', 'nomor induk']);
        $iSt = Xlsx::col($hdr, ['status (h/i/s/a)', 'status']);
        $iNote = Xlsx::col($hdr, ['catatan (opsional)', 'catatan', 'keterangan']);
        if ($iNis === null) {
            throw new UserError('Kolom NIS tidak ditemukan. Gunakan template yang diunduh.');
        }
        $byNis = Student::where('class_id', $data['class_id'])->get()->keyBy(fn ($s) => mb_strtolower(trim($s->nis)));
        $entries = [];
        $warn = [];
        foreach ($rows as $n => $r) {
            $nis = trim((string) ($r[$iNis] ?? ''));
            if ($nis === '') {
                continue;
            }
            $st = strtoupper(trim((string) ($iSt !== null ? ($r[$iSt] ?? '') : ''))) ?: 'H';
            $s = $byNis[mb_strtolower($nis)] ?? null;
            $okSt = in_array($st, ['H', 'I', 'S', 'A'], true);
            if (! $s) {
                $warn[] = 'Baris '.($n + 2).": NIS '$nis' tidak terdaftar di kelas ini.";
            }
            if (! $okSt) {
                $warn[] = 'Baris '.($n + 2).": status '$st' tidak dikenal (H/I/S/A).";
            }
            $entries[] = ['nis' => $nis, 'student' => $s, 'status' => $st, 'ok' => $s && $okSt, 'notes' => trim((string) ($iNote !== null ? ($r[$iNote] ?? '') : ''))];
        }

        return view('admin.hardcopy', [
            'classes' => SchoolClass::orderBy('name')->get(), 'today' => Dates::today(),
            'preview' => ['class' => SchoolClass::find($data['class_id']), 'date' => $data['date'], 'entries' => $entries, 'warn' => $warn,
                'valid' => count(array_filter($entries, fn ($e) => $e['ok'])), 'invalid' => count(array_filter($entries, fn ($e) => ! $e['ok']))],
        ]);
    }

    public function commit(Request $request)
    {
        $data = $request->validate(['class_id' => 'required|integer', 'date' => 'required|date_format:Y-m-d', 'e' => 'required|array']);
        $entries = [];
        foreach ($data['e'] as $sid => $e) {
            $entries[] = ['student_id' => (int) $sid, 'status' => (string) ($e['status'] ?? ''), 'notes' => (string) ($e['notes'] ?? '')];
        }
        $r = AttendanceService::submit($this->me(), (int) $data['class_id'], null, $data['date'], $entries, 'upload_hardcopy');

        return redirect()->route('admin.hardcopy')->with('success', "Upload hardcopy tersimpan: {$r['created']} baru, {$r['updated']} diperbarui.");
    }
}
