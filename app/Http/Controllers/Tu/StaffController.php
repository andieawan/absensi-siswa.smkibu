<?php

namespace App\Http\Controllers\Tu;

use App\Models\StaffAttendance;
use App\Services\StaffService;
use App\Services\TuService;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Absensi guru & staf. */
class StaffController extends TuController
{
    public function index(Request $request)
    {
        $tanggal = Dates::valid($request->query('date')) && ! Dates::isFuture($request->query('date')) ? $request->query('date') : Dates::today();

        return view('tu.staff', [
            'tanggal' => $tanggal, 'staff' => StaffService::staff(),
            'existing' => StaffAttendance::where('tanggal', $tanggal)->get()->keyBy('user_id'),
            'canWrite' => $request->user()->can('tu'),
            'locked' => ! $request->user()->isAdmin() && ! \App\Services\Rules::withinEditWindow($tanggal),
        ]);
    }

    public function store(Request $request)
    {
        $tanggal = (string) $request->input('date');
        $r = StaffService::save($this->me(), $tanggal, (array) $request->input('status', []), (array) $request->input('notes', []));

        return redirect()->route('tu.staff', ['date' => $tanggal])->with('success', "Absensi guru & staf tersimpan ({$r['created']} baru, {$r['updated']} diperbarui).");
    }

    private function bulan(Request $request): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('bulan')) ? $request->query('bulan') : substr(Dates::today(), 0, 7);
    }

    public function recap(Request $request)
    {
        $bulan = $this->bulan($request);
        [$y, $m] = array_map('intval', explode('-', $bulan));

        return view('tu.staff-recap', ['bulan' => $bulan, 'label' => TuService::BULAN[$m].' '.$y, 'rows' => StaffService::recap($bulan), 'print' => $request->boolean('cetak')]);
    }

    public function export(Request $request)
    {
        $bulan = $this->bulan($request);
        $rows = [['No', 'Nama', 'Peran', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Dinas Luar', 'Terlambat', 'Hari Tercatat', '% Kehadiran']];
        foreach (StaffService::recap($bulan) as $i => $r) {
            $rows[] = [$i + 1, $r['user']->nama, $r['user']->roleLabel(), $r['H'], $r['I'], $r['S'], $r['A'], $r['D'], $r['T'], $r['total'], $r['rate'] === null ? '' : $r['rate'].'%'];
        }

        return Xlsx::download("Rekap_Absensi_Guru_Staf_$bulan.xlsx", ['Guru & Staf' => $rows]);
    }
}
