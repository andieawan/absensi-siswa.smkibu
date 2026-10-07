<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\UserError;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\AdminService;
use App\Services\DeleteService;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    private function rules(): array
    {
        return ['nama' => 'required|string|max:191', 'jk' => 'required|in:L,P', 'class_id' => 'required|integer', 'status' => ['required', Rule::in(array_keys(Student::STATUSES))],
            'nama_ortu' => 'nullable|string|max:191', 'telp_ortu' => 'nullable|string|max:32'];
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $fc = (int) $request->query('class');

        return view('admin.students', [
            'classes' => SchoolClass::orderBy('name')->get(), 'q' => $q, 'fc' => $fc,
            'list' => Student::with('schoolClass')->when($fc, fn ($x) => $x->where('class_id', $fc))
                ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('nama', 'like', "%$q%")->orWhere('nis', 'like', "%$q%")))
                ->orderBy('class_id')->orderBy('nama')->paginate(50)->withQueryString(),
        ]);
    }

    public function store(Request $request)
    {
        AdminService::saveStudent($this->me(), null, $request->validate($this->rules() + ['nis' => 'required|string|max:64']));

        return back()->with('success', 'Siswa ditambahkan.');
    }

    public function update(Request $request, Student $student)
    {
        AdminService::saveStudent($this->me(), $student, $request->validate($this->rules()));

        return back()->with('success', "Data {$student->nama} diperbarui (NIS tidak berubah).");
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|max:5120']);
        $f = $request->file('file');
        if (! in_array(strtolower($f->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
            throw new UserError('Format berkas harus .xlsx atau .csv.');
        }
        [$hdr, $rows] = Xlsx::readWithHeader($f->getRealPath(), $f->getClientOriginalName());
        [$ok, $skip] = AdminService::importStudents($this->me(), $hdr, $rows);
        $msg = "Impor selesai: $ok siswa ditambahkan.".($skip ? ' Dilewati: '.implode('; ', array_slice($skip, 0, 8)).(count($skip) > 8 ? '; …' : '') : '');

        return back()->with($skip ? 'warning' : 'success', $msg);
    }

    public function destroy(Student $student)
    {
        DeleteService::deleteStudent($this->me(), $student);

        return back()->with('success', "Siswa {$student->nama} dihapus.");
    }

    /** Hapus beberapa siswa terpilih, atau semua siswa di satu kelas (hanya yang belum punya riwayat). */
    public function bulkDestroy(Request $request)
    {
        $in = $request->validate(['ids' => 'array', 'ids.*' => 'integer', 'class_id' => 'nullable|integer']);
        $ids = $in['ids'] ?? [];
        if (! $ids && ! empty($in['class_id'])) {
            $ids = Student::where('class_id', $in['class_id'])->pluck('id')->all();
        }
        if (! $ids) {
            throw new UserError('Pilih siswa yang akan dihapus.');
        }
        $r = DeleteService::deleteStudents($this->me(), $ids);
        $msg = "{$r['deleted']} siswa dihapus.".($r['skipped'] ? ' Tidak dihapus karena sudah punya riwayat ('.count($r['skipped']).'): '.implode('; ', array_slice($r['skipped'], 0, 5)).(count($r['skipped']) > 5 ? '; …' : '').'. Ubah statusnya (Nonaktif/Pindah/Keluar) sebagai gantinya.' : '');

        return back()->with($r['skipped'] ? 'warning' : 'success', $msg);
    }
}
