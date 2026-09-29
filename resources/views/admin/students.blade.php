@extends('layouts.app', ['title' => 'Data Siswa'])
@section('content')
@include('admin._tabs')
@php($classOpts = $classes->pluck('name', 'id')->all())
<div class="grid g2" style="align-items:start">
    <details class="card" @if($errors->any() && old('nis')) open @endif><summary style="cursor:pointer;font-weight:700">+ Tambah Siswa</summary>
        <form method="post" action="{{ route('admin.students.store') }}" style="margin-top:12px">@csrf
            <div class="fields">
                <div><label>NIS</label><input type="text" name="nis" value="{{ old('nis') }}" required></div>
                <div><label>Nama</label><input type="text" name="nama" value="{{ old('nama') }}" required></div>
                <div><label>JK</label>@include('partials.select', ['name' => 'jk', 'options' => ['L' => 'Laki-laki', 'P' => 'Perempuan'], 'selected' => old('jk')])</div>
                <div><label>Kelas</label>@include('partials.select', ['name' => 'class_id', 'options' => $classOpts, 'selected' => old('class_id', $fc)])</div>
                <div><label>Status</label>@include('partials.select', ['name' => 'status', 'options' => \App\Models\Student::STATUSES, 'selected' => old('status', 'aktif')])</div>
            </div>
            <button class="btn btn-pri" style="margin-top:12px">Tambah</button>
        </form>
    </details>
    <details class="card"><summary style="cursor:pointer;font-weight:700">⇪ Impor Siswa (xlsx / csv)</summary>
        <form method="post" enctype="multipart/form-data" action="{{ route('admin.students.import') }}" style="margin-top:12px">@csrf
            <p class="mut" style="font-size:12px">Kolom: <b>NIS, Nama Siswa, JK, Kelas</b> (nama kelas persis seperti di data kelas). NIS yang sudah ada dilewati.</p>
            <input type="file" name="file" accept=".xlsx,.csv" required><button class="btn btn-pri" style="margin-top:10px">Impor</button>
        </form>
    </details>
</div>
<form method="get" action="{{ route('admin.students') }}" class="card">
    <div class="fields">
        <div><label>Cari</label><input type="search" name="q" value="{{ $q }}" placeholder="Nama / NIS"></div>
        <div><label>Kelas</label><select name="class" data-auto><option value="0">Semua</option>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $fc)>{{ $c->name }}</option>@endforeach</select></div>
        <div><button class="btn">Cari</button></div>
    </div>
</form>
<div class="card card-tight"><div class="scroll"><table class="tbl tbl-cards">
    <thead><tr><th>NIS</th><th>Nama</th><th>JK</th><th>Kelas</th><th>Status</th><th class="r">Edit</th></tr></thead>
    <tbody>
    @forelse($list as $s)
        <tr><td class="mono" data-label="NIS">{{ $s->nis }}</td><td class="card-title" style="order:-1"><b>{{ $s->nama }}</b></td><td data-label="JK">{{ $s->jk }}</td><td data-label="Kelas">{{ $s->schoolClass->name ?? '-' }}</td>
            <td data-label="Status"><span @class(['badge', 'b-ok' => $s->status === 'aktif', 'b-warn' => $s->status !== 'aktif'])>{{ $s->status }}</span></td>
            <td class="r"><details><summary class="link-summary">Ubah data</summary>
                <form method="post" action="{{ route('admin.students.update', $s) }}" style="text-align:left;min-width:240px;margin:8px 0">@csrf @method('PUT')
                    <div class="field"><label>Nama</label><input type="text" name="nama" value="{{ $s->nama }}" required></div>
                    <div class="field"><label>JK</label>@include('partials.select', ['name' => 'jk', 'options' => ['L' => 'Laki-laki', 'P' => 'Perempuan'], 'selected' => $s->jk])</div>
                    <div class="field"><label>Kelas</label>@include('partials.select', ['name' => 'class_id', 'options' => $classOpts, 'selected' => $s->class_id])</div>
                    <div class="field"><label>Status</label>@include('partials.select', ['name' => 'status', 'options' => \App\Models\Student::STATUSES, 'selected' => $s->status])</div>
                    <small>NIS tidak dapat diubah.</small><br><button class="btn btn-sm btn-pri" style="margin-top:6px">Simpan</button>
                </form></details></td></tr>
    @empty
        <tr><td colspan="6" class="empty">Belum ada siswa.</td></tr>
    @endforelse
    </tbody>
</table></div>@include('partials.pager', ['p' => $list])</div>
@endsection
