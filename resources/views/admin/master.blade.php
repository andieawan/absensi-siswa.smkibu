@extends('layouts.app', ['title' => 'Kelas & Mapel'])
@section('content')
@include('admin._tabs')
@php
    $sem = ['Ganjil' => 'Ganjil', 'Genap' => 'Genap'];
    // Pilihan jurusan: dari daftar Jurusan (bila siap); nilai lama yang belum terdaftar tetap bisa dipilih.
    $majorSel = function ($current) use ($majorOpts) {
        if ($majorOpts === null) { return null; }
        $o = ['' => '— tanpa jurusan —'] + $majorOpts;
        if (trim((string) $current) !== '' && ! array_key_exists($current, $o)) { $o[$current] = $current.' (belum terdaftar)'; }
        return $o;
    };
@endphp
<div class="grid g2" style="align-items:start">
    <div class="card"><h2>Kelas</h2>
        <p class="hint" style="margin:0 0 8px">Mau membuat banyak kelas sekaligus (mis. X RPL 1–3)? Pakai tab <a href="{{ route('admin.majors') }}">Jurusan</a>.</p>
        @foreach($classes as $c)
            <details style="border-top:1px solid var(--line2);padding:8px 0"><summary style="cursor:pointer"><b>{{ $c->name }}</b> <small>{{ $c->jurusan }} · {{ $c->students_count }} siswa · {{ $c->tahun_ajaran }} {{ $c->semester }}</small></summary>
                <form method="post" action="{{ route('admin.classes.update', $c) }}" style="margin-top:8px">@csrf @method('PUT')
                    <div class="fields">
                        <div><label>Nama</label><input type="text" name="name" value="{{ $c->name }}" required></div>
                        <div><label>Jurusan</label>@if($o = $majorSel($c->jurusan))@include('partials.select', ['name' => 'jurusan', 'options' => $o, 'selected' => $c->jurusan])@else<input type="text" name="jurusan" value="{{ $c->jurusan }}">@endif</div>
                        <div><label>Angkatan</label><input type="text" name="angkatan" value="{{ $c->angkatan }}"></div>
                        <div><label>Tahun ajaran</label><input type="text" name="tahun_ajaran" value="{{ $c->tahun_ajaran }}" placeholder="2026/2027"></div>
                        <div><label>Semester</label>@include('partials.select', ['name' => 'semester', 'options' => $sem, 'selected' => $c->semester])</div>
                    </div><button class="btn btn-sm btn-pri" style="margin-top:8px">Simpan</button>
                </form>
                <form method="post" action="{{ route('admin.classes.destroy', $c) }}" class="inline" style="margin-top:8px" data-confirm="Hapus kelas {{ $c->name }}? Hanya bisa bila kelas kosong dan belum punya riwayat.">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Hapus kelas</button></form></details>
        @endforeach
        <details open style="border-top:1px solid var(--line);padding-top:10px;margin-top:8px"><summary style="cursor:pointer;font-weight:700">+ Tambah kelas</summary>
            <form method="post" action="{{ route('admin.classes.store') }}" style="margin-top:8px">@csrf
                <div class="fields">
                    <div><label>Nama</label><input type="text" name="name" placeholder="X RPL 1" required></div>
                    <div><label>Jurusan</label>@if($o = $majorSel(''))@include('partials.select', ['name' => 'jurusan', 'options' => $o, 'selected' => ''])@else<input type="text" name="jurusan">@endif</div>
                    <div><label>Angkatan</label><input type="text" name="angkatan"></div>
                    <div><label>Tahun ajaran</label><input type="text" name="tahun_ajaran" value="{{ $settings->tahun_ajaran }}" placeholder="2026/2027"></div>
                    <div><label>Semester</label>@include('partials.select', ['name' => 'semester', 'options' => $sem, 'selected' => $settings->semester])</div>
                </div><button class="btn btn-pri" style="margin-top:8px">Tambah Kelas</button>
            </form></details>
    </div>
    <div class="card"><h2>Mata Pelajaran</h2>
        @foreach($subjects as $s)
            <form method="post" action="{{ route('admin.subjects.update', $s) }}" class="row" style="margin-bottom:6px">@csrf @method('PUT')
                <input type="text" name="name" value="{{ $s->name }}" style="flex:1" required aria-label="Nama mapel"><button class="btn btn-sm">Simpan</button></form>
            @unless((int) $s->id === (int) config('absensi.bk_subject_id'))
            <form method="post" action="{{ route('admin.subjects.destroy', $s) }}" class="inline" data-confirm="Hapus mapel {{ $s->name }}? Hanya bisa bila belum dipakai di presensi/nilai.">@csrf @method('DELETE')<button class="btn btn-sm btn-danger" style="margin:-2px 0 8px">Hapus {{ $s->name }}</button></form>
            @endunless
        @endforeach
        <form method="post" action="{{ route('admin.subjects.store') }}" class="row" style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px">@csrf
            <input type="text" name="name" placeholder="Nama mata pelajaran baru" style="flex:1" required aria-label="Mapel baru"><button class="btn btn-pri btn-sm">Tambah</button></form>
        <small class="mut">Mapel ber-ID {{ config('absensi.bk_subject_id') }} dianggap “Bimbingan Konseling” dan disembunyikan dari daftar mapel reguler.</small>
    </div>
</div>
@endsection
