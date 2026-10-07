<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\AdminService;
use App\Services\DeleteService;
use Illuminate\Http\Request;

/** Data master: kelas & mata pelajaran. */
class MasterController extends Controller
{
    private const CLASS_RULES = ['name' => 'required|string|max:191', 'jurusan' => 'nullable|string|max:191', 'angkatan' => 'nullable|string|max:32', 'tahun_ajaran' => 'nullable|string|max:32', 'semester' => 'nullable|in:Ganjil,Genap'];

    public function index()
    {
        return view('admin.master', ['classes' => SchoolClass::withCount('students')->orderBy('name')->get(), 'subjects' => Subject::orderBy('name')->get()]);
    }

    public function storeClass(Request $request)
    {
        AdminService::saveClass($this->me(), null, $request->validate(self::CLASS_RULES));

        return back()->with('success', 'Kelas ditambahkan.');
    }

    public function updateClass(Request $request, SchoolClass $class)
    {
        AdminService::saveClass($this->me(), $class, $request->validate(self::CLASS_RULES));

        return back()->with('success', 'Kelas diperbarui.');
    }

    public function storeSubject(Request $request)
    {
        AdminService::saveSubject($this->me(), null, $request->validate(['name' => 'required|string|max:191'])['name']);

        return back()->with('success', 'Mata pelajaran ditambahkan.');
    }

    public function updateSubject(Request $request, Subject $subject)
    {
        AdminService::saveSubject($this->me(), $subject, $request->validate(['name' => 'required|string|max:191'])['name']);

        return back()->with('success', 'Mata pelajaran diperbarui.');
    }

    public function destroyClass(SchoolClass $class)
    {
        DeleteService::deleteClass($this->me(), $class);

        return back()->with('success', "Kelas {$class->name} dihapus.");
    }

    public function destroySubject(Subject $subject)
    {
        DeleteService::deleteSubject($this->me(), $subject);

        return back()->with('success', "Mapel {$subject->name} dihapus.");
    }
}
