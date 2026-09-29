@extends('layouts.app', ['title' => 'Dashboard'])
@section('content')
@php
    $rc = fn ($r) => $r >= 90 ? 'ok' : ($r >= 80 ? 'warn' : 'bad');
    $title = match ($variant) {
        'wali' => 'Dashboard Wali Kelas — '.($class->name ?? '-'),
        'sekolah' => 'Dashboard Sekolah (Kepsek) — Agregat Harian',
        default => 'Dashboard Guru Mapel — '.($subject->name ?? '-'),
    };
    $q = fn (array $o = []) => route('dashboard', array_filter(array_merge(['v' => $variant, 'class' => $variant === 'wali' ? null : $classId, 'subject' => $variant === 'mapel' ? $subjectId : null, 'cat' => $cat === 'all' ? null : $cat], $o), fn ($v) => $v !== null));
@endphp
<div class="head">
    <div>
        <h1>{{ $title }}</h1>
        <small>Tahun Ajaran {{ $settings->tahun_ajaran ?: ($class->tahun_ajaran ?? '-') }} · Semester {{ $settings->semester ?: '-' }} · <span class="mono">{{ count($trend) }}</span> pertemuan tercatat</small>
    </div>
    <form method="get" action="{{ route('dashboard') }}" class="row">
        @if(count($variants) > 1)
            <div class="seg" role="group" aria-label="Jenis dashboard">
                @foreach($variants as $k => $label)<a href="{{ route('dashboard', ['v' => $k]) }}" @class(['on' => $variant === $k])>{{ $label }}</a>@endforeach
            </div>
        @endif
        <input type="hidden" name="v" value="{{ $variant }}">
        @if($variant !== 'wali')
            <select name="class" data-auto aria-label="Pilih kelas" style="width:auto">
                @if($variant === 'sekolah')<option value="0">Semua Kelas (Agregat)</option>@endif
                @foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $classId)>{{ $c->name }} ({{ $c->jurusan }})</option>@endforeach
            </select>
        @endif
        @if($variant === 'mapel')
            <select name="subject" data-auto aria-label="Pilih mata pelajaran" style="width:auto">
                @foreach($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@endforeach
            </select>
        @endif
        <noscript><button class="btn btn-sm">Terapkan</button></noscript>
    </form>
</div>

@if($classes->isEmpty())
    <div class="alert alert-info">Belum ada data kelas. @can('admin')<a href="{{ route('admin.master') }}">Tambahkan di Admin Panel → Kelas &amp; Mapel</a>.@else Hubungi administrator.@endcan</div>
@endif
@if($variant === 'sekolah')
    <div class="alert alert-info">Aturan agregasi sekolah: data dihitung murni dari absensi harian wali kelas (bukan gabungan mapel) agar tidak terjadi double-counting.</div>
@endif

@php($me = auth()->user())
@can('lihat-sekolah')
    @php($ds = \App\Services\SchoolReports::dailyStatus(\App\Support\Dates::today()))
    @php($dsTotal = count(array_filter($ds, fn ($r) => $r['students'] > 0)))
    @php($dsDone = count(array_filter($ds, fn ($r) => $r['filled'] > 0)))
    @if($dsTotal)
    <a class="today-strip" href="{{ route('monitor') }}">
        <span><b>{{ $dsDone }}/{{ $dsTotal }} kelas</b> sudah mengisi absen harian hari ini</span>
        <span class="meter" style="flex:1;min-width:80px;margin:0"><i class="{{ $dsDone >= $dsTotal ? 'f-ok' : 'f-warn' }}" style="width:{{ round($dsDone / $dsTotal * 100) }}%"></i></span>
        <span class="btn btn-sm">{{ $dsDone < $dsTotal ? 'Lihat yang belum ›' : 'Lihat ›' }}</span>
    </a>
    @endif
@endcan
@if($me->hasRole('guru', 'admin', 'superadmin') || $me->kelas_wali_id)
@php($waliDone = \App\Services\SchoolReports::waliFilledToday($me))
<nav class="quick" aria-label="Aksi cepat">
    @if($me->kelas_wali_id)<a @class(['quick-item', 'primary' => ! $waliDone]) href="{{ route('attendance', ['mode' => 'wali']) }}"><svg class="ic"><use href="#i-check"/></svg><span><b>{{ $waliDone ? 'Absensi Harian ✓' : 'Isi Absensi Harian' }}</b><small>{{ $waliDone ? 'Sudah diisi hari ini · ubah bila perlu' : 'Belum diisi hari ini' }}</small></span></a>@endif
    <a @class(['quick-item', 'primary' => ! $me->kelas_wali_id]) href="{{ route('attendance', ['mode' => 'mapel']) }}"><svg class="ic"><use href="#i-check"/></svg><span><b>Absensi Mapel</b><small>Per jam pelajaran</small></span></a>
    <a class="quick-item" href="{{ route('grades') }}"><svg class="ic"><use href="#i-star"/></svg><span><b>Input Nilai</b><small>Tugas, ulangan, susulan</small></span></a>
    <a class="quick-item" href="{{ route('students') }}"><svg class="ic"><use href="#i-users"/></svg><span><b>Cari Siswa</b><small>Riwayat & surat</small></span></a>
    @if($me->kelas_wali_id)<a class="quick-item" href="{{ route('recap') }}"><svg class="ic"><use href="#i-check"/></svg><span><b>Rekap untuk Rapor</b><small>Sakit · Izin · Tanpa Ket. per semester</small></span></a>@endif
</nav>
@endif

<div class="kpis">
    <div class="kpi kpi-main"><small>Tingkat Kehadiran</small><div class="n mono {{ $rc($counts['rate']) }}">{{ number_format($counts['rate'], 1) }}%</div>
        <div class="meter" aria-hidden="true"><i class="f-{{ $rc($counts['rate']) }}" style="width:{{ min(100, $counts['rate']) }}%"></i><b style="left:85%"></b></div>
        <small>Target sekolah ≥ 85% · {{ $counts['total'] }} catatan</small></div>
    <div class="kpi"><small>Hadir</small><div class="n mono ok">{{ $counts['hadir'] }}</div></div>
    <div class="kpi"><small>Izin</small><div class="n mono">{{ $counts['izin'] }}</div></div>
    <div class="kpi"><small>Sakit</small><div class="n mono warn">{{ $counts['sakit'] }}</div></div>
    <div class="kpi"><small>Alpa</small><div class="n mono bad">{{ $counts['alpa'] }}</div></div>
</div>

<div class="narr">
    <h2>Ringkasan &amp; Saran Otomatis Berdasarkan Kondisi Data</h2>
    <div>{{ $narrative['summary'] }}</div>
    <div class="rec">→ {{ $narrative['recommendation'] }}</div>
</div>

<div class="split">
    <div class="card">
        <h2>Tren Kehadiran dari Waktu ke Waktu</h2><small>Persentase siswa hadir per tanggal sesi</small>
        @php($recent = array_slice(array_reverse($trend), 0, 10))
        @php($older = array_slice(array_reverse($trend), 10))
        @php($bar = fn ($t) => '<div class="bar"><span class="d">'.e(preg_replace('/ \d{4}$/', '', \App\Support\Dates::human($t['date']))).'</span><span class="t"><i class="f-'.$rc($t['pct']).'" style="width:'.$t['pct'].'%"></i></span><span class="p mono">'.$t['pct'].'%</span><span class="m mono">'.$t['h'].'/'.$t['total'].'</span></div>')
        @if($trend)<p class="mut" style="font-size:12px;margin:6px 0 0">10 sesi terakhir, terbaru di atas.</p>@endif
        @forelse($recent as $t){!! $bar($t) !!}@empty
            <div class="empty">Belum ada data absensi untuk pilihan ini.</div>
        @endforelse
        @if($older)<details class="more"><summary>Tampilkan {{ count($older) }} sesi sebelumnya</summary>@foreach($older as $t){!! $bar($t) !!}@endforeach</details>@endif
    </div>
    <div class="card">
        <h2>Deteksi Pola Absen Berkala</h2>
        <p class="mut" style="font-size:12px">Siswa yang berulang kali absen pada <b>hari yang sama</b> dengan jarak ~14 hari (toleransi 10–18 hari).</p>
        @forelse($patterns as $a)
            <div class="pola">
                <div class="row" style="justify-content:space-between"><a href="{{ route('students', ['id' => $a['student_id']]) }}"><b>{{ $a['student_name'] }}</b></a>
                    <span @class(['badge', 'b-bad' => $a['status_type'] === 'Pola', 'b-warn' => $a['status_type'] !== 'Pola'])>{{ $a['status_type'] }} ({{ $a['count'] }}x)</span></div>
                <div>Selalu absen di hari <b>{{ $a['day_of_week'] }}</b></div>
                <div class="mono mut" style="font-size:11px">Tanggal: {{ implode(', ', $a['dates']) }}</div>
            </div>
        @empty
            <div class="empty">Tidak terdeteksi pola berkala pada rentang ini.</div>
        @endforelse
    </div>
</div>

<div class="card">
    <div class="head" style="border:0;padding:0;margin-bottom:10px">
        <div>
            <h2>Daftar Siswa Perlu Perhatian @if($dual)<span class="badge b-bad">{{ $dual }} sinyal prioritas (absen + nilai turun)</span>@endif</h2>
            <small>Diurutkan berdasarkan tingkat signifikansi ketidakhadiran &amp; dampak akademik</small>
        </div>
        <div class="seg">
            <a href="{{ $q(['cat' => null]) }}" @class(['on' => $cat === 'all'])>Semua ({{ count($attention) }})</a>
            @foreach(['alpa_tinggi' => 'Alpa Tinggi', 'sakit_tinggi' => 'Sakit Tinggi', 'izin_tinggi' => 'Izin Tinggi', 'jarang_masuk_gabungan' => 'Jarang Masuk'] as $k => $label)
                <a href="{{ $q(['cat' => $k]) }}" @class(['on' => $cat === $k])>{{ $label }}</a>
            @endforeach
        </div>
    </div>
    @if(! $shown)
        <div class="empty">Tidak ada siswa dalam kategori ini. Seluruh siswa terpantau tertib.</div>
    @else
    <div class="scroll"><table class="tbl tbl-cards">
        <thead><tr><th>Nama Siswa</th><th>NIS</th><th>Kategori</th><th class="c">Alpa</th><th class="c">Izin</th><th class="c">Sakit</th><th class="c">Total</th><th>Sinyal</th><th></th></tr></thead>
        <tbody>
        @foreach($shown as $s)
            <tr>
                <td class="card-title"><a href="{{ route('students', ['id' => $s['student_id']]) }}"><b>{{ $s['student_name'] }}</b></a></td><td class="mono mut" data-label="NIS">{{ $s['nis'] }}</td><td data-label="Kategori"><span class="badge b-warn">{{ \App\Services\Analytics::CATEGORIES[$s['category']] }}</span></td>
                <td class="c mono bad" data-label="Alpa"><b>{{ $s['alpa'] }}</b></td><td class="c mono" data-label="Izin">{{ $s['izin'] }}</td><td class="c mono warn" data-label="Sakit">{{ $s['sakit'] }}</td><td class="c mono" data-label="Total absen"><b>{{ $s['total'] }}</b></td>
                <td data-label="Nilai">@if($s['grade_drop'])<span class="badge b-bad">Nilai ikut turun</span>@else<span class="mut">Normal</span>@endif</td>
                <td class="r"><a class="btn btn-sm" href="{{ route('students', ['id' => $s['student_id']]) }}">Lihat riwayat</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
@endsection
