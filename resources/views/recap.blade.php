@extends('layouts.app', ['title' => 'Rekap Kehadiran Rapor', 'bare' => $print])
@section('content')
@php
    $q = ['class' => $classId, 'semester' => $semester, 'dari' => $from, 'sampai' => $to];
    $sum = ['S' => array_sum(array_column($rows, 'S')), 'I' => array_sum(array_column($rows, 'I')), 'A' => array_sum(array_column($rows, 'A'))];
@endphp
@if($print)
    @push('head')<style>body.bare{background:#fff}.wrap-bare{max-width:900px}@media print{.no-print{display:none!important}}</style>@endpush
    <div class="no-print row" style="margin-bottom:12px"><a class="btn btn-sm" href="{{ route('recap', $q) }}">‹ Kembali</a><button type="button" class="btn btn-sm btn-pri" onclick="window.print()">Cetak / Simpan PDF</button></div>
    <div style="text-align:center;margin-bottom:14px"><h1 style="margin:0">REKAP KETIDAKHADIRAN SISWA</h1>
        <div>{{ $settings->school_name }}</div>
        <div>Kelas <b>{{ $class->name }}</b> · Semester {{ $semester }} · Tahun Ajaran {{ $tahun }}</div>
        <small>Periode {{ \App\Support\Dates::long($from) }} – {{ \App\Support\Dates::long($to) }}</small></div>
@else
<div class="head">
    <div><h1>Rekap Kehadiran untuk Rapor</h1><small>Jumlah Sakit / Izin / Tanpa Keterangan per siswa (absen harian) — siap disalin ke e-Rapor.</small></div>
    <div class="row">
        <a class="btn btn-sm" href="{{ route('recap', $q + ['cetak' => 1]) }}" target="_blank">Cetak</a>
        <a class="btn btn-sm btn-pri" href="{{ route('recap.export', $q) }}">Unduh Excel</a>
    </div>
</div>
<form method="get" action="{{ route('recap') }}" class="card filter-card">
    <div class="fields">
        @if($classes->count() > 1)
            <div><label for="class">Kelas</label><select id="class" name="class">@foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $classId)>{{ $c->name }}</option>@endforeach</select></div>
        @else<input type="hidden" name="class" value="{{ $classId }}">@endif
        <div><label for="semester">Semester</label><select id="semester" name="semester" onchange="this.form.dari.value='';this.form.sampai.value='';this.form.submit()">@foreach(['Ganjil', 'Genap'] as $s)<option @selected($s === $semester)>{{ $s }}</option>@endforeach</select></div>
        <div><label for="dari">Dari tanggal</label><input id="dari" type="date" name="dari" value="{{ $from }}"></div>
        <div><label for="sampai">Sampai tanggal</label><input id="sampai" type="date" name="sampai" value="{{ $to }}"></div>
        <div style="align-self:end"><button class="btn">Tampilkan</button></div>
    </div>
</form>
<p class="hint">Tahun ajaran {{ $tahun }} · Kelas {{ $class->name }} · periode {{ \App\Support\Dates::long($from) }} – {{ \App\Support\Dates::long($to) }}. Ubah tanggal bila semester dimulai/berakhir di hari lain.</p>
@endif

<div @class(['card card-tight' => ! $print])>
    @if(! $rows)
        <div class="empty">Belum ada siswa di kelas ini.</div>
    @else
    <div class="scroll"><table @class(['tbl', 'tbl-cards' => ! $print]) @if($print) border="1" style="border-collapse:collapse" @endif>
        <thead><tr><th class="c">No</th><th>NIS</th><th>Nama Siswa</th><th class="c">Sakit</th><th class="c">Izin</th><th class="c">Tanpa Ket.</th><th class="c">Hari Tercatat</th><th class="c">% Hadir</th></tr></thead>
        <tbody>
        @foreach($rows as $i => $r)
            <tr>
                <td class="c mut">{{ $i + 1 }}</td>
                <td class="mono mut" data-label="NIS">{{ $r['student']->nis }}</td>
                <td class="card-title"><b>{{ $r['student']->nama }}</b>@if($r['student']->class_id !== $classId || $r['student']->status !== 'aktif') <small class="mut">({{ $r['student']->status !== 'aktif' ? \App\Models\Student::STATUSES[$r['student']->status] ?? $r['student']->status : 'pindah kelas' }})</small>@endif</td>
                <td class="c mono warn" data-label="Sakit">{{ $r['S'] }}</td>
                <td class="c mono" data-label="Izin">{{ $r['I'] }}</td>
                <td class="c mono bad" data-label="Tanpa keterangan">{{ $r['A'] }}</td>
                <td class="c mono mut" data-label="Hari tercatat">{{ $r['total'] }}</td>
                <td class="c mono" data-label="% hadir">@if($r['rate'] === null)–@else<span @class(['low' => $r['rate'] < 85])>{{ $r['rate'] }}%</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="3" class="r">Jumlah</th><th class="c mono">{{ $sum['S'] }}</th><th class="c mono">{{ $sum['I'] }}</th><th class="c mono">{{ $sum['A'] }}</th><th colspan="2"></th></tr></tfoot>
    </table></div>
    @endif
</div>
@if($print)
    <div style="display:flex;justify-content:flex-end;margin-top:36px"><div style="text-align:center;min-width:240px">Wali Kelas {{ $class->name }},<br><br><br><br><b>{{ \App\Models\User::where('kelas_wali_id', $class->id)->value('nama') ?? '........................' }}</b></div></div>
@endif
@endsection
