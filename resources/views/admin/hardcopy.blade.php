@extends('layouts.app', ['title' => 'Upload Hardcopy'])
@section('content')
@include('admin._tabs')
<p style="margin:0 0 12px"><a href="{{ route('admin.import') }}">← Kembali ke Impor Data</a></p>
@php($classOpts = $classes->pluck('name', 'id')->all())
@if($preview)
    <form method="post" action="{{ route('admin.hardcopy.commit') }}" class="card card-tight">@csrf
        <input type="hidden" name="class_id" value="{{ $preview['class']->id }}"><input type="hidden" name="date" value="{{ $preview['date'] }}">
        <div style="padding:16px"><h2>Pratinjau — {{ $preview['class']->name }}, {{ $preview['date'] }}</h2>
            <p><span class="badge b-ok">{{ $preview['valid'] }} valid</span> <span @class(['badge', 'b-bad' => $preview['invalid']])>{{ $preview['invalid'] }} bermasalah (tidak disimpan)</span></p>
            @if($preview['warn'])<div class="alert alert-warn"><b>Peringatan:</b><ul>@foreach(array_slice($preview['warn'], 0, 15) as $w)<li>{{ $w }}</li>@endforeach</ul></div>@endif
        </div>
        <div class="scroll"><table class="tbl"><thead><tr><th>NIS</th><th>Nama</th><th class="c">Status</th><th>Catatan</th><th>Hasil</th></tr></thead><tbody>
        @foreach($preview['entries'] as $e)
            <tr><td class="mono">{{ $e['nis'] }}</td><td>{{ $e['student']->nama ?? 'Tidak dikenal' }}</td><td class="c"><b>{{ $e['status'] }}</b></td><td>{{ $e['notes'] }}</td>
                <td>@if($e['ok'])<span class="badge b-ok">OK</span>
                    <input type="hidden" name="e[{{ $e['student']->id }}][status]" value="{{ $e['status'] }}"><input type="hidden" name="e[{{ $e['student']->id }}][notes]" value="{{ $e['notes'] }}">
                @else<span class="badge b-bad">Lewati</span>@endif</td></tr>
        @endforeach
        </tbody></table></div>
        <div class="sticky-save"><a class="btn" href="{{ route('admin.hardcopy') }}">Batal</a><button class="btn btn-pri" @disabled(! $preview['valid'])>Simpan {{ $preview['valid'] }} Data</button></div>
    </form>
@else
    <div class="grid g2" style="align-items:start">
        <div class="card"><h2>1. Unduh template</h2>
            <form method="get" action="{{ route('admin.hardcopy.template') }}" class="fields">
                <div><label>Kelas</label>@include('partials.select', ['name' => 'class', 'options' => $classOpts])</div>
                <div><label>Tanggal</label><input type="date" name="date" value="{{ $today }}"></div>
                <div><button class="btn" data-multi>Unduh Template Excel</button></div>
            </form></div>
        <div class="card"><h2>2. Unggah &amp; pratinjau</h2>
            <form method="post" enctype="multipart/form-data" action="{{ route('admin.hardcopy.preview') }}">@csrf
                <div class="fields">
                    <div><label>Kelas</label>@include('partials.select', ['name' => 'class_id', 'options' => $classOpts, 'selected' => old('class_id')])</div>
                    <div><label>Tanggal presensi</label><input type="date" name="date" value="{{ old('date', $today) }}" required></div>
                </div>
                <div class="field" style="margin-top:12px"><input type="file" name="file" accept=".xlsx,.csv" required></div>
                <button class="btn btn-pri">Pratinjau</button>
            </form></div>
    </div>
    <div class="alert alert-info">Data tersimpan sebagai absensi harian dengan jalur “upload_hardcopy”. Administrator boleh memasukkan data lebih dari 7 hari ke belakang.</div>
@endif
@endsection
