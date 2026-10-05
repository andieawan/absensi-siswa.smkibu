<?php

namespace App\Http\Controllers;

use App\Models\BkRecord;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\BkService;
use App\Support\BkModules;
use App\Support\Dates;
use App\Support\TahunAjaran;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Modul BK terpadu: Ringkasan + Pelanggaran, Buku Kasus, Prestasi, Home Visit, Riwayat Surat. */
class BkRecordController extends Controller
{
    public function __construct()
    {
        if (! \App\Support\DbUpdate::bkReady()) {
            throw new \App\Exceptions\UserError('Modul BK belum aktif: Admin perlu membuka Pengaturan lalu klik "Perbarui Database Sekarang".');
        }
    }

    private function module(string $jenis): array
    {
        return BkModules::get($jenis) ?? abort(404);
    }

    /** Kelas yang siswanya boleh dipilih di form tambah. */
    private function writableClasses(string $jenis)
    {
        $u = $this->me();
        if (BkService::isCounselor($u)) {
            return SchoolClass::orderBy('name')->get();
        }

        return BkService::canWrite($u, $jenis) ? SchoolClass::whereKey($u->kelas_wali_id)->get() : collect();
    }

    public function home()
    {
        $u = $this->me();
        [$from, $to] = TahunAjaran::yearRange();
        $base = fn () => BkService::visible($u)->whereBetween('tanggal', [$from, $to]);
        $stats = [];
        foreach (BkModules::all() as $jenis => $m) {
            $stats[$jenis] = [
                'total' => $base()->where('jenis', $jenis)->count(),
                'proses' => $m['status'] ? $base()->where('jenis', $jenis)->where('status', 'Proses')->count() : null,
            ];
        }
        $byLevel = $base()->where('jenis', 'pelanggaran')->selectRaw('kategori, COUNT(*) AS n')->groupBy('kategori')->pluck('n', 'kategori');

        return view('bk.home', [
            'stats' => $stats, 'byLevel' => $byLevel, 'tahun' => TahunAjaran::label(),
            'latest' => BkService::visible($u)->with(['student', 'schoolClass'])->latest('id')->limit(10)->get(),
            'active' => $base()->where('status', 'Proses')->with(['student', 'schoolClass'])->orderBy('tanggal')->limit(10)->get(),
        ]);
    }

    public function index(Request $request, string $jenis)
    {
        $m = $this->module($jenis);
        $u = $this->me();
        $fc = (int) $request->query('class');
        $status = in_array($request->query('status'), BkModules::STATUS, true) ? $request->query('status') : '';
        $q = trim((string) $request->query('q'));
        $records = BkService::visible($u, $jenis)->with(['student', 'schoolClass', 'author'])
            ->when($fc, fn ($x) => $x->where('class_id', $fc))
            ->when($status, fn ($x) => $x->where('status', $status))
            ->when($request->query('siswa'), fn ($x) => $x->where('student_id', (int) $request->query('siswa')))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('judul', 'like', "%$q%")
                ->orWhereIn('student_id', Student::where('nama', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")->pluck('id'))))
            ->orderByDesc('tanggal')->orderByDesc('id')->paginate(25)->withQueryString();
        $writable = $this->writableClasses($jenis);

        return view('bk.records', [
            'jenis' => $jenis, 'm' => $m, 'records' => $records, 'fc' => $fc, 'status' => $status, 'q' => $q,
            'filterClasses' => BkService::seesAll($u) ? SchoolClass::orderBy('name')->get() : SchoolClass::whereKey($u->kelas_wali_id ?? -1)->get(),
            'writable' => $writable,
            'pickStudents' => $writable->isEmpty() ? collect() : Student::active()->whereIn('class_id', $writable->pluck('id'))->orderBy('nama')->get()->groupBy('class_id'),
            'today' => Dates::today(), 'preselect' => (int) $request->query('siswa'),
        ]);
    }

    public function store(Request $request, string $jenis)
    {
        $this->module($jenis);
        $request->validate(['berkas' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,webp,heic,pdf']);
        BkService::save($this->me(), $jenis, null, $request->all(), $request->file('berkas'));

        return back()->with('success', BkModules::get($jenis)['label'].' berhasil dicatat.');
    }

    public function update(Request $request, string $jenis, BkRecord $record)
    {
        abort_unless($record->jenis === $jenis, 404);
        $request->validate(['berkas' => 'nullable|file|max:8192|mimes:jpg,jpeg,png,webp,heic,pdf']);
        BkService::save($this->me(), $jenis, $record, $request->all(), $request->file('berkas'));

        return back()->with('success', 'Catatan diperbarui.');
    }

    public function destroy(string $jenis, BkRecord $record)
    {
        abort_unless($record->jenis === $jenis, 404);
        BkService::delete($this->me(), $record);

        return back()->with('success', 'Catatan dihapus.');
    }

    public function file(string $jenis, BkRecord $record)
    {
        $u = $this->me();
        abort_unless($record->jenis === $jenis && $record->berkas_path, 404);
        abort_unless(BkService::visible($u)->whereKey($record->id)->exists() && ! BkService::isMasked($u, $record), 403);

        return Storage::disk('local')->response($record->berkas_path);
    }
}
