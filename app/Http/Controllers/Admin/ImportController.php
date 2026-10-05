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
        [$hdr, $rows] = Xlsx::readWithHeader($f->getRealPath(), $f->getClientOriginalName());
        $r = ImportService::run($this->me(), $type, $hdr, $rows, $request->boolean('dry'));

        return redirect()->route('admin.import')->with('import_result', ['type' => $type, 'title' => ImportService::types()[$type]['title']] + $r);
    }
}
