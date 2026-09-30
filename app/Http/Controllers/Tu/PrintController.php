<?php

namespace App\Http\Controllers\Tu;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\Audit;
use App\Services\TuService;
use App\Support\Dates;
use App\Support\TahunAjaran;
use Illuminate\Http\Request;

/** Cetak dokumen: daftar hadir kosong, daftar siswa, kartu siswa. */
class PrintController extends TuController
{
    private function cls(Request $r): SchoolClass
    {
        return SchoolClass::find((int) $r->query('class')) ?? abort(404, 'Pilih kelas terlebih dahulu.');
    }

    public function index()
    {
        return view('tu.print', ['classes' => SchoolClass::withCount(['students as aktif_count' => fn ($q) => $q->where('status', 'aktif')])->orderBy('name')->get(), 'bulan' => substr(Dates::today(), 0, 7)]);
    }

    public function sheet(Request $request)
    {
        $c = $this->cls($request);
        $bulan = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('bulan')) ? $request->query('bulan') : substr(Dates::today(), 0, 7);
        [$y, $m] = array_map('intval', explode('-', $bulan));
        $days = [];
        for ($d = 1, $n = (int) gmdate('t', strtotime("$bulan-01 UTC")); $d <= $n; $d++) {
            $ymd = sprintf('%s-%02d', $bulan, $d);
            if (Dates::weekday($ymd) !== 0) { // hari Minggu dilewati
                $days[] = $d;
            }
        }
        Audit::log('Cetak Daftar Hadir', 'TU', $this->me()->nama, "{$c->name} $bulan");

        return view('tu.print-sheet', ['class' => $c, 'label' => TuService::BULAN[$m].' '.$y, 'days' => $days,
            'students' => Student::active()->where('class_id', $c->id)->orderBy('nama')->get(), 'wali' => \App\Models\User::where('kelas_wali_id', $c->id)->value('nama')]);
    }

    public function list(Request $request)
    {
        $c = $this->cls($request);
        Audit::log('Cetak Daftar Siswa', 'TU', $this->me()->nama, $c->name);

        return view('tu.print-list', ['class' => $c, 'cols' => max(0, min(8, (int) $request->query('kolom', 3))), 'title' => trim((string) $request->query('judul')),
            'students' => Student::active()->where('class_id', $c->id)->orderBy('nama')->get(), 'tahun' => TahunAjaran::label()]);
    }

    public function cards(Request $request)
    {
        $c = $this->cls($request);
        Audit::log('Cetak Kartu Siswa', 'TU', $this->me()->nama, $c->name);

        return view('tu.print-cards', ['class' => $c, 'students' => Student::active()->where('class_id', $c->id)->orderBy('nama')->get(), 'tahun' => TahunAjaran::label()]);
    }
}
