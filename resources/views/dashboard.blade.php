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

<div class="grid g5" style="margin-bottom:16px">
    <div class="kpi"><small>Tingkat Kehadiran</small><div class="n mono {{ $rc($counts['rate']) }}">{{ number_format($counts['rate'], 1) }}%</div><small>Target sekolah: ≥ 85%</small></div>
    <div class="kpi"><small>Hadir (H)</small><div class="n mono">{{ $counts['hadir'] }}</div></div>
    <div class="kpi"><small>Izin (I)</small><div class="n mono">{{ $counts['izin'] }}</div></div>
    <div class="kpi"><small>Sakit (S)</small><div class="n mono warn">{{ $counts['sakit'] }}</div></div>
    <div class="kpi"><small>Alpa (A)</small><div class="n mono bad">{{ $counts['alpa'] }}</div></div>
</div>

<div class="narr">
    <h2>Ringkasan &amp; Saran Otomatis Berdasarkan Kondisi Data</h2>
    <div>{{ $narrative['summary'] }}</div>
    <div class="rec">→ {{ $narrative['recommendation'] }}</div>
</div>

<div class="split">
    <div class="card">
        <h2>Tren Kehadiran dari Waktu ke Waktu</h2><small>Persentase siswa hadir per tanggal sesi</small>
        @forelse($trend as $t)
            <div class="bar"><span class="d mono">{{ $t['date'] }}</span><span class="t"><i class="f-{{ $rc($t['pct']) }}" style="width:{{ $t['pct'] }}%"></i></span><span class="p mono">{{ $t['pct'] }}%</span><span class="m mono">({{ $t['h'] }}/{{ $t['total'] }})</span></div>
        @empty
            <div class="empty">Belum ada data rekaman absensi untuk kombinasi ini.</div>
        @endforelse
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
    <div class="scroll"><table class="tbl">
        <thead><tr><th>Nama Siswa</th><th>NIS</th><th>Kategori</th><th class="c">Alpa</th><th class="c">Izin</th><th class="c">Sakit</th><th class="c">Total</th><th>Sinyal</th><th></th></tr></thead>
        <tbody>
        @foreach($shown as $s)
            <tr>
                <td><b>{{ $s['student_name'] }}</b></td><td class="mono mut">{{ $s['nis'] }}</td><td>{{ \App\Services\Analytics::CATEGORIES[$s['category']] }}</td>
                <td class="c mono bad"><b>{{ $s['alpa'] }}</b></td><td class="c mono">{{ $s['izin'] }}</td><td class="c mono warn">{{ $s['sakit'] }}</td><td class="c mono"><b>{{ $s['total'] }}</b></td>
                <td>@if($s['grade_drop'])<span class="bad"><b>Nilai &amp; kehadiran anjlok</b></span>@else<span class="mut">Normal</span>@endif</td>
                <td class="r"><a href="{{ route('students', ['id' => $s['student_id']]) }}">Lihat riwayat →</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
@endsection
