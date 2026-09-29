<?php

namespace App\Http\Controllers;

use App\Services\SchoolReports;
use App\Support\Dates;
use Illuminate\Http\Request;

/** Pantau: kelas mana yang sudah/belum mengisi absen harian pada suatu tanggal. */
class MonitorController extends Controller
{
    public function __invoke(Request $request)
    {
        $tanggal = Dates::valid($request->query('date')) && ! Dates::isFuture($request->query('date')) ? $request->query('date') : Dates::today();
        $rows = SchoolReports::dailyStatus($tanggal);
        $filter = $request->query('f') === 'belum' ? 'belum' : 'semua';

        return view('monitor', [
            'tanggal' => $tanggal, 'filter' => $filter,
            'rows' => $filter === 'belum' ? array_values(array_filter($rows, fn ($r) => ! $r['filled'] && $r['students'])) : $rows,
            'total' => count(array_filter($rows, fn ($r) => $r['students'] > 0)),
            'done' => count(array_filter($rows, fn ($r) => $r['filled'] > 0)),
            'weekend' => in_array(Dates::weekday($tanggal), [0, 6], true),
        ]);
    }
}
