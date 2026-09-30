@extends('layouts.app', ['title' => 'Daftar Siswa '.$class->name, 'bare' => true, 'width' => '860px'])
@push('head')<style>@page{size:A4 portrait;margin:14mm}body.bare{background:#fff}.sheet-t{border-collapse:collapse;width:100%;font-size:13px}.sheet-t th,.sheet-t td{border:1px solid #000;padding:4px 6px}.sheet-t td{height:26px}@media print{.no-print{display:none!important}}</style>@endpush
@section('content')
@include('tu._printbar')
<div style="text-align:center;margin-bottom:10px"><b style="font-size:16px">{{ mb_strtoupper($title ?: 'DAFTAR SISWA') }}</b><br>{{ $settings->school_name }}<br>Kelas <b>{{ $class->name }}</b> · Tahun Ajaran {{ $tahun }}</div>
<table class="sheet-t"><thead><tr><th style="width:34px">No</th><th style="width:130px">NIS</th><th>Nama Siswa</th><th style="width:34px">JK</th>@for($i = 0; $i < $cols; $i++)<th style="min-width:60px">&nbsp;</th>@endfor</tr></thead>
    <tbody>@foreach($students as $i => $s)<tr><td style="text-align:center">{{ $i + 1 }}</td><td>{{ $s->nis }}</td><td>{{ $s->nama }}</td><td style="text-align:center">{{ $s->jk }}</td>@for($k = 0; $k < $cols; $k++)<td></td>@endfor</tr>@endforeach</tbody></table>
<p style="font-size:12px;margin-top:8px">Jumlah siswa: {{ $students->count() }}</p>
@endsection
