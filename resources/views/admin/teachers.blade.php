@extends('layouts.app', ['title' => 'Akun Guru'])
@section('content')
@include('admin._tabs')
@php($classOpts = ['' => 'Bukan wali kelas'] + $classes->pluck('name', 'id')->all())
@php($subjOpts = $subjects->pluck('name', 'id')->all())
@php($classChk = $classes->pluck('name', 'id')->all())
@php($subjNames = $subjects->pluck('name', 'id'))
<details class="card" @if($errors->any() && old('username')) open @endif><summary style="cursor:pointer;font-weight:700">+ Tambah Akun Guru</summary>
    <form method="post" action="{{ route('admin.teachers.store') }}" style="margin-top:12px">@csrf
        <div class="fields">
            <div><label>Nama lengkap</label><input type="text" name="nama" value="{{ old('nama') }}" required></div>
            <div><label>Username</label><input type="text" name="username" value="{{ old('username') }}" autocapitalize="none" required></div>
            <div><label>Password awal (min. 8)</label><input type="text" name="password" minlength="8" required autocomplete="off"></div>
            <div><label>Wali kelas (opsional)</label>@include('partials.select', ['name' => 'kelas_wali_id', 'options' => $classOpts, 'selected' => old('kelas_wali_id')])</div>
        </div>
        <div class="field" style="margin-top:12px"><label>Peran</label>@include('admin._checks', ['name' => 'roles', 'items' => $roles, 'sel' => old('roles', ['guru'])])</div>
        <div class="field"><label>Mata pelajaran diampu</label>@include('admin._checks', ['name' => 'subjects', 'items' => $subjOpts, 'sel' => old('subjects', [])])</div>
        <div class="field"><label>Kelas diajar</label>@include('admin._checks', ['name' => 'classes', 'items' => $classChk, 'sel' => old('classes', [])])<p class="hint" style="margin:4px 0 0">Mapel &amp; kelas dicentang di sini berlaku <b>silang</b> (semua mapel di semua kelas). Bila mapel berbeda tiap kelas (mis. B. Indonesia di X DKV 1, B. Daerah di X DKV 2), atur di tab <a href="{{ route('admin.pairings') }}">Pasangan Mapel</a>.</p></div>
        <button class="btn btn-pri">Tambah Akun</button>
    </form>
</details>
<div class="card card-tight"><div class="scroll"><table class="tbl tbl-cards">
    <thead><tr><th>Nama</th><th>Username</th><th>Peran</th><th>Wali</th><th>Mapel</th><th>Status</th><th class="r">Aksi</th></tr></thead>
    <tbody>
    @foreach($users as $u)
        <tr>
            <td class="card-title"><b>{{ $u->nama }}</b></td><td class="mono" data-label="Username">{{ $u->username }}</td><td data-label="Peran">{{ $u->roleLabel() }}</td>
            <td data-label="Wali">{{ $classOpts[$u->kelas_wali_id] ?? '-' }}</td>
            <td style="font-size:12.5px" data-label="Mapel">{{ collect($u->subjects)->map(fn ($i) => $subjNames[$i] ?? $i)->implode(', ') ?: '-' }}</td>
            <td data-label="Status"><span @class(['badge', 'b-ok' => $u->is_active, 'b-bad' => ! $u->is_active])>{{ $u->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
            <td class="r"><form method="post" action="{{ route('admin.teachers.toggle', $u) }}" class="inline" data-confirm="{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }} akun {{ $u->username }}?">@csrf<button @class(['btn', 'btn-sm', 'btn-danger' => $u->is_active])>{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></td>
        </tr>
        <tr class="sub-row"><td colspan="7" style="padding:0;border-bottom:1px solid var(--line)"><details style="padding:6px 12px"><summary class="link-summary">Ubah / reset password — {{ $u->username }}</summary>
            <div class="grid g2" style="margin:10px 0;align-items:start">
                <form method="post" action="{{ route('admin.teachers.reset', $u) }}">@csrf
                    <label>Password baru (min. 8)</label><div class="row"><input type="text" name="password" minlength="8" required autocomplete="off" style="flex:1"><button class="btn btn-sm">Reset</button></div></form>
                <form method="post" action="{{ route('admin.teachers.update', $u) }}">@csrf @method('PUT')
                    <div class="field"><label>Nama</label><input type="text" name="nama" value="{{ $u->nama }}" required></div>
                    <div class="field"><label>Wali kelas</label>@include('partials.select', ['name' => 'kelas_wali_id', 'options' => $classOpts, 'selected' => $u->kelas_wali_id])</div>
                    <div class="field"><label>Peran</label>@include('admin._checks', ['name' => 'roles', 'items' => $roles, 'sel' => $u->roles])</div>
                    <div class="field"><label>Mapel</label>@include('admin._checks', ['name' => 'subjects', 'items' => $subjOpts, 'sel' => $u->subjects])</div>
                    <div class="field"><label>Kelas</label>@include('admin._checks', ['name' => 'classes', 'items' => $classChk, 'sel' => $u->classes])</div>
                    <button class="btn btn-sm btn-pri">Simpan Perubahan</button></form>
            </div></details></td></tr>
    @endforeach
    </tbody>
</table></div></div>
@endsection
