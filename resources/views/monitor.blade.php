@extends('layouts.app', ['title' => 'Pantau Absensi'])
@section('content')
@php
    $today = \App\Support\Dates::today();
    $shift = fn (int $d) => gmdate('Y-m-d', strtotime($tanggal.' UTC') + $d * 86400);
    $url = fn (array $o = []) => route('monitor', array_merge(['date' => $tanggal, 'f' => $filter === 'belum' ? 'belum' : null], $o));
    $pct = $total ? round($done / $total * 100) : 0;
@endphp
<div class="head">
    <div><h1>Pantau Absensi Harian</h1><small>{{ \App\Support\Dates::human($tanggal) }}@if($tanggal === $today) <span class="badge b-ok">Hari ini</span>@endif</small></div>
    <div class="seg" role="group" aria-label="Tampilkan">
        <a @class(['on' => $filter === 'semua']) href="{{ $url(['f' => null]) }}">Semua kelas</a>
        <a @class(['on' => $filter === 'belum']) href="{{ $url(['f' => 'belum']) }}">Belum mengisi</a>
    </div>
</div>

<form method="get" action="{{ route('monitor') }}" class="card filter-card">
    @if($filter === 'belum')<input type="hidden" name="f" value="belum">@endif
    <div class="fields"><div class="date-pick">
        <label for="date">Tanggal</label>
        <div class="date-row">
            <a class="btn btn-icon" href="{{ $url(['date' => $shift(-1)]) }}" aria-label="Hari sebelumnya">‹</a>
            <input id="date" type="date" name="date" value="{{ $tanggal }}" max="{{ $today }}" onchange="this.form.submit()">
            @if($tanggal < $today)<a class="btn btn-icon" href="{{ $url(['date' => $shift(1)]) }}" aria-label="Hari berikutnya">›</a>@else<span class="btn btn-icon" aria-disabled="true">›</span>@endif
            @if($tanggal !== $today)<a class="btn btn-sm" href="{{ $url(['date' => $today]) }}">Hari ini</a>@endif
        </div>
    </div><noscript><div><button class="btn">Tampilkan</button></div></noscript></div>
</form>

<div class="kpis kpis-3">
    <div class="kpi kpi-main"><small>Kelas sudah mengisi</small><div class="n">{{ $done }}<span class="mut" style="font-size:18px"> / {{ $total }}</span></div>
        <div class="meter"><i class="{{ $pct >= 100 ? 'f-ok' : ($pct >= 50 ? 'f-warn' : 'f-bad') }}" style="width:{{ $pct }}%"></i></div><small>{{ $pct }}% kelas</small></div>
    <div class="kpi"><small>Belum mengisi</small><div class="n {{ $total - $done > 0 ? 'bad' : 'ok' }}">{{ max(0, $total - $done) }}</div><small>kelas</small></div>
</div>

@if($weekend)<div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">Tanggal ini hari {{ \App\Support\Dates::weekday($tanggal) === 0 ? 'Minggu' : 'Sabtu' }} — wajar bila belum ada absensi.</span></div>@endif

<div class="card card-tight">
    @if(! $rows)
        <div class="empty">@if($filter === 'belum')<b>Semua kelas sudah mengisi absen harian. 🎉</b>@else Belum ada kelas.@endif</div>
    @else
    <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Kelas</th><th>Wali Kelas</th><th>Status</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th>Terakhir diisi</th></tr></thead><tbody>
    @foreach($rows as $r)
        <tr>
            <td class="card-title"><b>{{ $r['class']->name }}</b> <small class="mut">{{ $r['students'] }} siswa</small></td>
            <td data-label="Wali kelas">{{ $r['wali'] ?? '—' }}</td>
            <td data-label="Status">@if(! $r['students'])<span class="badge">Tanpa siswa</span>@elseif($r['filled'])<span class="badge b-ok">✓ Sudah</span>@if($r['filled'] < $r['students']) <small class="warn">{{ $r['filled'] }}/{{ $r['students'] }} siswa</small>@endif @else<span class="badge b-bad">Belum</span>@endif</td>
            <td class="c mono" data-label="Hadir">{{ $r['filled'] ? $r['H'] : '–' }}</td>
            <td class="c mono" data-label="Izin">{{ $r['filled'] ? $r['I'] : '–' }}</td>
            <td class="c mono warn" data-label="Sakit">{{ $r['filled'] ? $r['S'] : '–' }}</td>
            <td class="c mono bad" data-label="Alpa">{{ $r['filled'] ? $r['A'] : '–' }}</td>
            <td data-label="Terakhir diisi">@if($r['last'])<small>{{ \App\Support\Dates::local((int) (strtotime($r['last'].(str_ends_with($r['last'], 'Z') ? '' : ' UTC')) * 1000)) }} WIB<br><span class="mut">via {{ $r['via'] }}</span></small>@else<small class="mut">—</small>@endif</td>
        </tr>
    @endforeach
    </tbody></table></div>
    @endif
</div>
<p class="hint">Hanya absen <b>harian</b> (wali kelas / ketua kelas / BK) yang dihitung. Absen per mapel tidak termasuk.</p>
@endsection
