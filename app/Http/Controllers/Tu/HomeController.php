<?php

namespace App\Http\Controllers\Tu;

use App\Models\MutasiSiswa;
use App\Models\StaffAttendance;
use App\Models\Student;
use App\Models\TuSurat;
use App\Services\StaffService;
use App\Support\Dates;

class HomeController extends TuController
{
    public function __invoke()
    {
        $today = Dates::today();
        $bulan = substr($today, 0, 7);
        $staff = StaffService::staff()->count();

        return view('tu.home', [
            'siswaAktif' => Student::active()->count(),
            'mutasiMasuk' => MutasiSiswa::where('jenis', 'masuk')->where('tanggal', 'like', "$bulan%")->count(),
            'mutasiKeluar' => MutasiSiswa::where('jenis', 'keluar')->where('tanggal', 'like', "$bulan%")->count(),
            'masukBelum' => TuSurat::where('arah', 'masuk')->where('status', '!=', 'Selesai')->count(),
            'keluarBulan' => TuSurat::where('arah', 'keluar')->where('tanggal', 'like', "$bulan%")->count(),
            'staff' => $staff, 'staffHariIni' => StaffAttendance::where('tanggal', $today)->count(),
            'latest' => TuSurat::with('student')->orderByDesc('id')->limit(6)->get(),
        ]);
    }
}
