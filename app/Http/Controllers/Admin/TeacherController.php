<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\AdminService;
use Illuminate\Http\Request;
use App\Support\PasswordPolicy;

class TeacherController extends Controller
{
    private const FIELDS = [
        'nama' => 'required|string|max:191', 'roles' => 'required|array', 'roles.*' => 'string',
        'kelas_wali_id' => 'nullable|integer|exists:classes,id', 'subjects' => 'array', 'subjects.*' => 'integer', 'classes' => 'array', 'classes.*' => 'integer',
    ];

    public function index()
    {
        return view('admin.teachers', [
            'users' => User::orderBy('nama')->get(), 'classes' => SchoolClass::orderBy('name')->get(), 'subjects' => Subject::orderBy('name')->get(),
            'roles' => array_filter(AdminService::ROLES, fn ($k) => $k !== 'superadmin' || $this->me()->hasRole('superadmin'), ARRAY_FILTER_USE_KEY),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::FIELDS + ['username' => 'required|string|max:64', 'password' => ['required', 'string', PasswordPolicy::rule()]]);
        AdminService::addTeacher($this->me(), $data);

        return back()->with('success', 'Akun guru berhasil ditambahkan.');
    }

    public function update(Request $request, User $user)
    {
        AdminService::updateTeacher($this->me(), $user, $request->validate(self::FIELDS));

        return back()->with('success', "Akun {$user->username} diperbarui.");
    }

    public function reset(Request $request, User $user)
    {
        $data = $request->validate(['password' => ['required', 'string', PasswordPolicy::rule()]]);
        AdminService::resetPassword($this->me(), $user, $data['password']);

        return back()->with('success', "Password {$user->username} direset. Sesi login akun tersebut otomatis keluar.");
    }

    public function toggle(User $user)
    {
        $on = AdminService::toggle($this->me(), $user);

        return back()->with('success', $on ? "Akun {$user->username} diaktifkan." : "Akun {$user->username} dinonaktifkan.");
    }
}
