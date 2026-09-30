<?php

namespace App\Http\Controllers;

use App\Services\StaffService;
use App\Support\DbUpdate;
use Illuminate\Http\Request;

/** Absen mandiri guru & staf: masuk, pulang, atau lapor izin/sakit/dinas luar (hanya untuk hari ini). */
class SelfCheckController extends Controller
{
    public function index()
    {
        $me = $this->me();

        return view('selfcheck', ['state' => StaffService::state($me), 'ready' => DbUpdate::selfReady(), 'history' => DbUpdate::selfReady() ? StaffService::history($me) : collect()]);
    }

    public function store(Request $request)
    {
        $me = $this->me();
        if (! DbUpdate::selfReady()) {
            return back()->with('error', 'Fitur absen mandiri belum aktif: Admin perlu klik "Perbarui Database Sekarang" di Pengaturan.');
        }
        $lat = $request->input('lat');
        $lng = $request->input('lng');
        $msg = match ($request->input('aksi')) {
            'masuk' => ($r = StaffService::checkIn($me, $lat, $lng)) && $r->terlambat
                ? "Absen masuk tercatat pukul {$r->jam_masuk} (terlambat)."
                : "Absen masuk tercatat pukul {$r->jam_masuk}. Selamat bertugas!",
            'pulang' => 'Absen pulang tercatat pukul '.StaffService::checkOut($me, $lat, $lng)->jam_pulang.'. Terima kasih!',
            'lapor' => 'Laporan '.StaffService::STATUS[StaffService::report($me, (string) $request->input('status'), (string) $request->input('catatan'))->status].' hari ini tercatat.',
            default => throw new \App\Exceptions\UserError('Aksi tidak dikenal.'),
        };

        return redirect()->back()->with('success', $msg);
    }
}
