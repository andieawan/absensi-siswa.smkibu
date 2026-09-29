<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Otorisasi pasangan guru – mapel – kelas. */
class PairingController extends Controller
{
    public function index()
    {
        return view('admin.pairings', [
            'pairs' => Pairing::all(), 'users' => User::where('is_active', true)->orderBy('nama')->get(),
            'subjects' => Subject::orderBy('name')->get(), 'classes' => SchoolClass::orderBy('name')->get(),
        ]);
    }

    private function data(Request $r): array
    {
        return $r->validate(['user_id' => 'required|integer|exists:users,id', 'subject_id' => 'required|integer|exists:subjects,id', 'class_id' => 'required|integer|exists:classes,id']);
    }

    public function store(Request $request)
    {
        $d = $this->data($request);
        Pairing::firstOrCreate($d);
        Audit::log('Tambah Pasangan Mapel', 'Pasangan', $this->me()->nama, User::find($d['user_id'])->nama.' — '.Subject::find($d['subject_id'])->name.' — '.SchoolClass::find($d['class_id'])->name);

        return back()->with('success', 'Pasangan ditambahkan.');
    }

    public function destroy(Request $request)
    {
        $d = $this->data($request);
        Pairing::where($d)->delete();
        Audit::log('Hapus Pasangan Mapel', 'Pasangan', $this->me()->nama, "Guru #{$d['user_id']}, mapel #{$d['subject_id']}, kelas #{$d['class_id']}");

        return back()->with('success', 'Pasangan dihapus.');
    }
}
