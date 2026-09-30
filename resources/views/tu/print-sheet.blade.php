@extends('layouts.app', ['title' => 'Daftar Hadir '.$class->name, 'bare' => true, 'width' => '1120px'])
@push('head')<style>@page{size:A4 landscape;margin:10mm}body.bare{background:#fff}.sheet-t{border-collapse:collapse;width:100%;font-size:11px}.sheet-t th,.sheet-t td{border:1px solid #000;padding:2px 3px;text-align:center}.sheet-t td.l{text-align:left;white-space:nowrap}.sheet-t td{height:22px}@media print{.no-print{display:none!important}}</style>@endpush
@section('content')
@include('tu._printbar')
<div style="text-align:center;margin-bottom:8px"><b style="font-size:16px">DAFTAR HADIR SISWA</b><br>{{ $settings->school_name }}<br>Kelas <b>{{ $class->name }}</b> · Bulan <b>{{ $label }}</b>@if($wali) · Wali Kelas: {{ $wali }}@endif</div>
<table class="sheet-t">
    <thead><tr><th rowspan="2" style="width:26px">No</th><th rowspan="2">Nama Siswa</th><th colspan="{{ count($days) }}">Tanggal</th><th colspan="3">Jumlah</th></tr>
        <tr>@foreach($days as $d)<th style="width:22px">{{ $d }}</th>@endforeach<th style="width:24px">S</th><th style="width:24px">I</th><th style="width:24px">A</th></tr></thead>
    <tbody>@foreach($students as $i => $s)<tr><td>{{ $i + 1 }}</td><td class="l">{{ $s->nama }}</td>@foreach($days as $d)<td></td>@endforeach<td></td><td></td><td></td></tr>@endforeach</tbody>
</table>
<div style="display:flex;justify-content:space-between;margin-top:18px;font-size:12px"><span>Keterangan: H = Hadir · S = Sakit · I = Izin · A = Alpa</span><span style="text-align:center">Wali Kelas,<br><br><br>{{ $wali ?: '........................' }}</span></div>
@endsection
