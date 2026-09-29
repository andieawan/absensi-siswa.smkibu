<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\BkService;
use App\Services\SchoolReports;
use App\Support\Dates;
use App\Support\TahunAjaran;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Rekap ketidakhadiran semester (Sakit/Izin/Tanpa Keterangan) untuk rapor. */
class RecapController extends Controller
{
    private function params(Request $r): array
    {
        $u = $this->me();
        $all = BkService::seesAll($u);
        $classes = $all ? SchoolClass::orderBy('name')->get() : SchoolClass::whereKey($u->kelas_wali_id ?? -1)->get();
        abort_if($classes->isEmpty(), 403, 'Rekap semester tersedia untuk Wali Kelas, Guru BK, Kepala Sekolah, dan Administrator.');
        $classId = (int) ($r->query('class') ?: ($u->kelas_wali_id ?? $classes->first()->id));
        abort_unless($classes->contains('id', $classId), 403, 'Anda hanya dapat melihat rekap kelas wali Anda.');
        $semester = in_array($r->query('semester'), ['Ganjil', 'Genap'], true) ? $r->query('semester') : TahunAjaran::semester();
        [$dFrom, $dTo] = TahunAjaran::semesterRange($semester);
        $from = Dates::valid($r->query('dari')) ? $r->query('dari') : $dFrom;
        $to = Dates::valid($r->query('sampai')) ? $r->query('sampai') : $dTo;

        return compact('classes', 'classId', 'semester', 'from', 'to') + ['class' => $classes->firstWhere('id', $classId)];
    }

    public function index(Request $request)
    {
        $p = $this->params($request);

        return view('recap', $p + ['rows' => SchoolReports::semesterRecap($p['classId'], $p['from'], $p['to']), 'tahun' => TahunAjaran::label(), 'print' => $request->boolean('cetak')]);
    }

    public function export(Request $request)
    {
        $p = $this->params($request);
        $rows = [['No', 'NIS', 'Nama Siswa', 'Sakit', 'Izin', 'Tanpa Keterangan', 'Hadir', 'Hari Tercatat', '% Kehadiran']];
        foreach (SchoolReports::semesterRecap($p['classId'], $p['from'], $p['to']) as $i => $r) {
            $rows[] = [$i + 1, $r['student']->nis, $r['student']->nama, $r['S'], $r['I'], $r['A'], $r['H'], $r['total'], $r['rate'] === null ? '' : $r['rate'].'%'];
        }
        $name = 'Rekap_Kehadiran_Rapor_'.preg_replace('/[^A-Za-z0-9]+/', '_', $p['class']->name).'_'.$p['semester'].'_'.str_replace('/', '-', TahunAjaran::label()).'.xlsx';

        return Xlsx::download($name, ['Ketidakhadiran' => $rows]);
    }
}
