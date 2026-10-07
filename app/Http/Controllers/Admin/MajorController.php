<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Models\Jurusan;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Services\Audit;
use App\Support\DbUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Jurusan: daftar kode+nama, pembuatan kelas massal per tingkat/jurusan. */
class MajorController extends Controller
{
    public const TINGKAT = ['X', 'XI', 'XII'];

    private function ready(): void
    {
        if (! DbUpdate::jurusanReady()) {
            throw new UserError('Fitur ini butuh pembaruan database: Admin → Pengaturan → Perbarui Database Sekarang.');
        }
    }

    private function kode(Request $r, ?int $ignore = null): array
    {
        $d = $r->validate([
            'kode' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/]*$/'],
            'nama' => 'required|string|max:191',
        ], ['kode.regex' => 'Kode hanya huruf/angka (boleh spasi, - atau /).']);
        $d['kode'] = mb_strtoupper(trim($d['kode']));
        $d['nama'] = trim($d['nama']);
        if (Jurusan::where('kode', $d['kode'])->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            throw new UserError("Kode {$d['kode']} sudah ada.");
        }

        return $d;
    }

    public function index()
    {
        $ready = DbUpdate::jurusanReady();
        $classes = SchoolClass::withCount(['students as aktif_count' => fn ($q) => $q->where('status', 'aktif')])->orderBy('name')->get();
        $byCode = $classes->groupBy(fn ($c) => mb_strtoupper(trim((string) $c->jurusan)));
        $majors = $ready ? Jurusan::orderBy('kode')->get() : collect();
        $known = $majors->pluck('kode')->map(fn ($k) => mb_strtoupper($k))->all();
        $unknown = $byCode->keys()->filter(fn ($k) => $k !== '' && ! in_array($k, $known, true))->values();

        return view('admin.majors', [
            'ready' => $ready, 'majors' => $majors, 'byCode' => $byCode, 'unknown' => $unknown,
            'noMajor' => $byCode->get('', collect()), 'tingkat' => self::TINGKAT,
        ]);
    }

    public function store(Request $request)
    {
        $this->ready();
        $d = $this->kode($request);
        Jurusan::create($d);
        Audit::log('Tambah Jurusan', 'Data Master', $this->me()->nama, "{$d['kode']} — {$d['nama']}");

        return back()->with('success', "Jurusan {$d['kode']} ditambahkan.");
    }

    public function update(Request $request, Jurusan $jurusan)
    {
        $this->ready();
        $d = $this->kode($request, $jurusan->id);
        $old = $jurusan->kode;
        DB::transaction(function () use ($jurusan, $d, $old) {
            $jurusan->update($d);
            if (mb_strtoupper($old) !== $d['kode'] || $old !== $d['kode']) {
                SchoolClass::whereRaw('upper(trim(jurusan)) = ?', [mb_strtoupper($old)])->update(['jurusan' => $d['kode']]);
            }
        });
        Audit::log('Ubah Jurusan', 'Data Master', $this->me()->nama, "$old → {$d['kode']} — {$d['nama']}");

        return back()->with('success', "Jurusan {$d['kode']} diperbarui.");
    }

    public function destroy(Jurusan $jurusan)
    {
        $this->ready();
        $n = SchoolClass::whereRaw('upper(trim(jurusan)) = ?', [mb_strtoupper($jurusan->kode)])->count();
        if ($n > 0) {
            throw new UserError("Jurusan {$jurusan->kode} masih dipakai $n kelas. Pindahkan kelasnya dulu.");
        }
        $jurusan->delete();
        Audit::log('Hapus Jurusan', 'Data Master', $this->me()->nama, $jurusan->kode);

        return back()->with('success', "Jurusan {$jurusan->kode} dihapus.");
    }

    /** Daftarkan kode jurusan yang sudah dipakai kelas tetapi belum ada di daftar. */
    public function sync()
    {
        $this->ready();
        $have = Jurusan::pluck('kode')->map(fn ($k) => mb_strtoupper($k))->all();
        $n = 0;
        foreach (SchoolClass::whereNotNull('jurusan')->pluck('jurusan')->map(fn ($j) => trim((string) $j))->filter()->unique(fn ($j) => mb_strtoupper($j)) as $k) {
            if (! in_array(mb_strtoupper($k), $have, true)) {
                Jurusan::create(['kode' => mb_strtoupper($k), 'nama' => $k]);
                $n++;
            }
        }
        if ($n) {
            Audit::log('Sinkron Jurusan', 'Data Master', $this->me()->nama, "$n kode ditambahkan dari kelas");
        }

        return back()->with('success', $n ? "$n jurusan ditambahkan dari data kelas." : 'Semua jurusan di data kelas sudah terdaftar.');
    }

    /** Buat banyak kelas sekaligus: "{tingkat} {kode} {no}", mis. X RPL 1..3. Kelas yang sudah ada dilewati. */
    public function makeClasses(Request $request)
    {
        $this->ready();
        $d = $request->validate(['jurusan_id' => 'required|integer|exists:jurusan,id', 'tingkat' => 'required|in:'.implode(',', self::TINGKAT), 'jumlah' => 'required|integer|min:1|max:20']);
        $m = Jurusan::findOrFail($d['jurusan_id']);
        $s = SchoolSetting::current();
        $exist = SchoolClass::pluck('name')->map(fn ($n) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($n))))->all();
        $made = [];
        for ($i = 1; $i <= (int) $d['jumlah']; $i++) {
            $name = "{$d['tingkat']} {$m->kode} $i";
            if (in_array(mb_strtolower($name), $exist, true)) {
                continue;
            }
            SchoolClass::create(['name' => $name, 'jurusan' => $m->kode, 'tahun_ajaran' => $s->tahun_ajaran, 'semester' => $s->semester]);
            $made[] = $name;
        }
        if ($made) {
            Audit::log('Buat Kelas Massal', 'Data Master', $this->me()->nama, implode(', ', $made));
        }

        return back()->with('success', $made ? count($made).' kelas dibuat: '.implode(', ', $made).'.' : 'Semua kelas tersebut sudah ada; tidak ada yang ditambahkan.');
    }
}
