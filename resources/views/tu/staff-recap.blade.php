@extends('layouts.app', ['title' => 'Rekap Absensi Guru & Staf', 'bare' => $print, 'width' => $print ? '900px' : null])
@section('content')
@if($print)
    @push('head')<style>body.bare{background:#fff}@media print{.no-print{display:none!important}}</style>@endpush
    <div class="no-print row" style="margin-bottom:12px"><a class="btn btn-sm" href="{{ route('tu.staff.recap', ['bulan' => $bulan]) }}">‹ Kembali</a><button type="button" class="btn btn-sm btn-pri" onclick="window.print()">Cetak / Simpan PDF</button></div>
    <div style="text-align:center;margin-bottom:14px"><h1 style="margin:0">REKAP KEHADIRAN GURU &amp; STAF</h1><div>{{ $settings->school_name }}</div><div>Bulan {{ $label }}</div></div>
@else
    @include('tu._tabs', ['h' => 'Rekap Absensi Guru & Staf', 'sub' => 'Rekap kehadiran bulan '.$label.'.'])
    <form method="get" action="{{ route('tu.staff.recap') }}" class="card filter-card"><div class="fields">
        <div><label for="bulan">Bulan</label><input id="bulan" type="month" name="bulan" value="{{ $bulan }}" onchange="this.form.submit()"></div>
        <div style="align-self:end" class="row"><a class="btn btn-sm" target="_blank" href="{{ route('tu.staff.recap', ['bulan' => $bulan, 'cetak' => 1]) }}">Cetak</a><a class="btn btn-sm btn-pri" href="{{ route('tu.staff.export', ['bulan' => $bulan]) }}">Unduh Excel</a></div>
        <noscript><div><button class="btn">Tampilkan</button></div></noscript>
    </div></form>
@endif
<div @class(['card card-tight' => ! $print])>
    @if(! $rows)<div class="empty">Belum ada guru/staf aktif.</div>@else
    <div class="scroll"><table @class(['tbl', 'tbl-cards' => ! $print]) @if($print) border="1" style="border-collapse:collapse" @endif>
        <thead><tr><th class="c">No</th><th>Nama</th><th>Peran</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th class="c">D</th><th class="c">Terlambat</th><th class="c">Hari tercatat</th><th class="c">% Hadir</th></tr></thead>
        <tbody>
        @foreach($rows as $i => $r)
            <tr><td class="c mut">{{ $i + 1 }}</td><td class="card-title"><b>{{ $r['user']->nama }}</b></td><td data-label="Peran">{{ $r['user']->roleLabel() }}</td>
                <td class="c mono" data-label="Hadir">{{ $r['H'] }}</td><td class="c mono" data-label="Izin">{{ $r['I'] }}</td><td class="c mono warn" data-label="Sakit">{{ $r['S'] }}</td><td class="c mono bad" data-label="Alpa">{{ $r['A'] }}</td><td class="c mono" data-label="Dinas luar">{{ $r['D'] }}</td><td class="c mono warn" data-label="Terlambat">{{ $r['T'] }}</td>
                <td class="c mono mut" data-label="Hari tercatat">{{ $r['total'] }}</td><td class="c mono" data-label="% hadir">@if($r['rate'] === null)–@else{{ $r['rate'] }}%@endif</td></tr>
        @endforeach
        </tbody></table></div>@endif
</div>
@if($print)<p style="font-size:12px;margin-top:8px">H = Hadir, I = Izin, S = Sakit, A = Alpa, D = Dinas Luar (dihitung hadir pada persentase), Terlambat = absen masuk melewati batas jam (absen mandiri).</p>
<div style="display:flex;justify-content:flex-end;margin-top:36px"><div style="text-align:center;min-width:240px">Kepala Sekolah,<br><br><br><br><b>{{ $settings->kepsek_nama ?: '........................' }}</b></div></div>@endif
@endsection
