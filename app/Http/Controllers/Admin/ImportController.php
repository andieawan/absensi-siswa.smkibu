<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Services\ImportService;
use App\Support\Xlsx;
use Illuminate\Http\Request;

/** Impor massal (Kelas, Mapel, Siswa, Guru & Staf, Pasangan Mapel) dengan template unduhan. */
class ImportController extends Controller
{
    public function index()
    {
        return view('admin.import', ['types' => ImportService::types(), 'xlsx' => Xlsx::available()]);
    }

    public function template(string $type)
    {
        $t = ImportService::types()[$type] ?? abort(404);

        return Xlsx::download($t['file'], ImportService::templateSheets($type));
    }

    public function run(Request $request, string $type)
    {
        abort_unless(isset(ImportService::types()[$type]), 404);
        $request->validate(['file' => 'required|file|max:5120|mimes:xlsx,csv,txt']);
        $f = $request->file('file');
        if (! in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'csv', 'txt'], true)) {
            throw new UserError('Format berkas harus .xlsx atau .csv.');
        }
        if ($type === 'absensi') {
            [$hdr, $rows] = $this->mergeSheets(Xlsx::readAllSheets($f->getRealPath(), $f->getClientOriginalName()));
        } else {
            [$hdr, $rows] = Xlsx::readWithHeader($f->getRealPath(), $f->getClientOriginalName());
        }
        $r = ImportService::run($this->me(), $type, $hdr, $rows, $request->boolean('dry'));

        return redirect()->route('admin.import')->with('import_result', ['type' => $type, 'title' => ImportService::types()[$type]['title']] + $r);
    }

    /** Gabungkan semua sheet yang punya kolom Kelas & Tanggal; kolom disusun ulang mengikuti sheet pertama yang cocok. */
    private function mergeSheets(array $sheets): array
    {
        $hdr = null;
        $rows = [];
        foreach ($sheets as $sheet) {
            $h = array_map(fn ($v) => strtolower(trim((string) $v)), array_shift($sheet) ?? []);
            if (Xlsx::col($h, ['kelas']) === null || Xlsx::col($h, ['tanggal']) === null) {
                continue;
            }
            $hdr ??= $h;
            $map = array_map(fn ($name) => array_search($name, $h, true), $hdr);
            foreach ($sheet as $r) {
                $rows[] = array_map(fn ($i) => $i === false ? '' : (string) ($r[$i] ?? ''), $map);
            }
        }

        return [$hdr ?? [], $rows];
    }
}
