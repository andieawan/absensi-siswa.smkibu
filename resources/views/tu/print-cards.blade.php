@extends('layouts.app', ['title' => 'Kartu Siswa '.$class->name, 'bare' => true, 'width' => '860px'])
@push('head')<style>@page{size:A4 portrait;margin:10mm}body.bare{background:#fff}
.kartu-grid{display:grid;grid-template-columns:repeat(2,8.6cm);gap:6mm;justify-content:center}
.kartu{width:8.6cm;height:5.4cm;border:1px solid #333;border-radius:8px;padding:8px 10px;box-sizing:border-box;display:flex;flex-direction:column;break-inside:avoid;background:#fff;overflow:hidden}
.kartu .top{text-align:center;border-bottom:2px solid #4f46e5;padding-bottom:3px;line-height:1.15}.kartu .top b{font-size:9.5pt;display:block}.kartu .top span{font-size:7.5pt;letter-spacing:.08em}
.kartu .body{display:flex;gap:8px;flex:1;align-items:center}.kartu .foto{width:2.2cm;height:2.8cm;border:1px dashed #888;display:grid;place-items:center;font-size:7pt;color:#888;flex:none}
.kartu dl{margin:0;font-size:9pt;line-height:1.35}.kartu dt{font-size:7pt;color:#555;text-transform:uppercase}.kartu dd{margin:0 0 3px;font-weight:700}
@media print{.no-print{display:none!important}}</style>@endpush
@section('content')
@include('tu._printbar')
<div class="kartu-grid">
@foreach($students as $s)
    <div class="kartu"><div class="top"><b>{{ mb_strtoupper($settings->school_name) }}</b><span>KARTU SISWA</span></div>
        <div class="body"><div class="foto">Pas foto<br>3×4</div>
            <dl><dt>Nama</dt><dd>{{ $s->nama }}</dd><dt>NIS</dt><dd class="mono">{{ $s->nis }}</dd><dt>Kelas · Tahun ajaran</dt><dd>{{ $class->name }} · {{ $tahun }}</dd></dl></div></div>
@endforeach
</div>
@endsection
