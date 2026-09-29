<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\BackupService;
use App\Services\SchoolReports;
use App\Support\TahunAjaran;
use Illuminate\Http\Request;

/** Kenaikan kelas, kelulusan, dan pergantian tahun ajaran. */
class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $classes = SchoolClass::withCount(['students as aktif_count' => fn ($q) => $q->where('status', 'aktif')])->orderBy('name')->get();
        $from = $classes->firstWhere('id', (int) $request->query('dari'));
        [$a, $b] = TahunAjaran::years();

        return view('admin.promotion', [
            'classes' => $classes, 'from' => $from, 'target' => (string) $request->query('ke', ''),
            'students' => $from ? Student::where('class_id', $from->id)->where('status', 'aktif')->orderBy('nama')->get() : collect(),
            'nextYear' => ($a + 1).'/'.($b + 1), 'currentYear' => "$a/$b",
        ]);
    }

    public function promote(Request $request)
    {
        $data = $request->validate([
            'dari' => 'required|integer', 'ke' => 'required|string', 'siswa' => 'required|array|min:1', 'siswa.*' => 'integer',
        ], ['siswa.required' => 'Pilih minimal satu siswa.']);
        if ($data['ke'] !== 'lulus') {
            $occupied = Student::where('class_id', (int) $data['ke'])->where('status', 'aktif')->count();
            if ($occupied > 0 && ! $request->boolean('paham')) {
                return back()->withInput()->with('error', "Kelas tujuan masih berisi $occupied siswa aktif. Naikkan/luluskan kelas tujuan lebih dulu (mulai dari tingkat tertinggi), atau centang konfirmasi untuk menggabungkan.");
            }
        }
        $backup = BackupService::run(); // cadangan otomatis sebelum perubahan massal
        $n = SchoolReports::promote($this->me(), (int) $data['dari'], $data['ke'], $data['siswa']);
        $to = $data['ke'] === 'lulus' ? 'dinyatakan LULUS' : 'dipindah ke '.SchoolClass::find((int) $data['ke'])->name;

        return redirect()->route('admin.promotion')->with('success', "$n siswa $to.".($backup['success'] ? ' Cadangan database dibuat sebelum perubahan.' : ''));
    }

    public function newYear(Request $request)
    {
        $data = $request->validate(['tahun_ajaran' => 'required|string']);
        SchoolReports::startNewYear($this->me(), trim($data['tahun_ajaran']));

        return back()->with('success', 'Tahun ajaran '.$data['tahun_ajaran'].' (semester Ganjil) sudah aktif.');
    }
}
