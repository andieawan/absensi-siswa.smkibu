<?php

namespace App\Http\Controllers\Tu;

use App\Models\MutasiSiswa;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\TuService;
use App\Support\Dates;
use Illuminate\Http\Request;

class MutasiController extends TuController
{
    public function index(Request $request)
    {
        $jenis = in_array($request->query('jenis'), ['masuk', 'keluar'], true) ? $request->query('jenis') : '';
        $q = trim((string) $request->query('q'));
        $list = MutasiSiswa::with(['student', 'schoolClass'])
            ->when($jenis, fn ($x) => $x->where('jenis', $jenis))
            ->when($q !== '', fn ($x) => $x->whereIn('student_id', Student::where('nama', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")->pluck('id')))
            ->orderByDesc('tanggal')->orderByDesc('id')->paginate(25)->withQueryString();
        $canWrite = $request->user()->can('tu');

        return view('tu.mutasi', [
            'list' => $list, 'jenis' => $jenis, 'q' => $q, 'canWrite' => $canWrite, 'today' => Dates::today(),
            'classes' => SchoolClass::orderBy('name')->get(),
            'pickStudents' => $canWrite ? Student::active()->orderBy('nama')->get()->groupBy('class_id') : collect(),
        ]);
    }

    public function masuk(Request $request)
    {
        $s = TuService::mutasiMasuk($this->me(), $request->all());

        return redirect()->route('tu.mutasi')->with('success', "Siswa masuk {$s->nama} tercatat dan ditambahkan ke kelas {$s->schoolClass->name}.");
    }

    public function keluar(Request $request)
    {
        [$m, $surat] = TuService::mutasiKeluar($this->me(), $request->all(), $request->boolean('buat_surat'));
        $back = redirect()->route('tu.mutasi')->with('success', "Mutasi keluar {$m->student->nama} tercatat (status: ".TuService::ALASAN_KELUAR[$m->status_baru].').'
            .($surat ? " Surat keterangan pindah bernomor {$surat->nomor} dibuat." : ''));

        return $surat ? $back->with('print_url', route('tu.surat.print', $surat)) : $back;
    }
}
