@extends('layouts.app', ['title' => 'Info Kehadiran Kelas', 'bare' => true, 'width' => '720px'])
@push('head')<meta name="robots" content="noindex,nofollow">@endpush
@section('content')
<div class="head"><div><h1>Info Kehadiran Kelas</h1><small>{{ $settings->school_name }}</small></div></div>
@if($error)
    <div class="alert alert-error">{{ $error }}</div>
@else
    <div class="card">
        <h2>Kelas {{ $class->name ?? '-' }}</h2>
        @if($wali)<small>Wali kelas: {{ $wali->nama }}</small>@endif
        <form method="get" class="row" style="margin-top:10px">
            <select name="tgl" onchange="this.form.submit()" aria-label="Pilih tanggal">
                @foreach($dates as $d)<option value="{{ $d }}" @selected($d === $tgl)>{{ \App\Support\Dates::human($d) }}{{ $d === \App\Support\Dates::today() ? ' (hari ini)' : '' }}</option>@endforeach
            </select>
            <noscript><button class="btn btn-sm">Tampilkan</button></noscript>
        </form>
    </div>
    @if(! $recorded)
        <div class="alert alert-warn">Presensi hari ini belum dicatat wali kelas. Silakan cek kembali nanti.</div>
    @else
        <div class="grid g3" style="margin-bottom:16px;grid-template-columns:repeat(3,1fr)">
            <div class="kpi"><small>Hadir</small><div class="n mono">{{ $hadir }}</div></div>
            <div class="kpi"><small>Tidak masuk</small><div class="n mono {{ $absent->isEmpty() ? '' : 'bad' }}">{{ $absent->count() }}</div></div>
            <div class="kpi"><small>Tercatat</small><div class="n mono">{{ $total }}</div></div>
        </div>
        <div class="card card-tight"><div style="padding:14px 16px"><h2>Siswa yang tidak masuk</h2></div>
            @if($absent->isEmpty())
                <div class="empty">Alhamdulillah, semua siswa masuk.</div>
            @else
                <table class="tbl"><thead><tr><th>Nama</th><th>Keterangan</th></tr></thead><tbody>
                @foreach($absent as $r)
                    <tr><td><b>{{ $r->student->nama }}</b></td><td><span @class(['badge', 'b-warn' => in_array($r->status, ['I', 'S'], true), 'b-bad' => $r->status === 'A'])>{{ \App\Models\Attendance::STATUS[$r->status] ?? $r->status }}</span></td></tr>
                @endforeach
                </tbody></table>
            @endif
        </div>
    @endif
    <p class="mut" style="font-size:12px">Bila ananda tidak masuk tetapi belum ada keterangan, mohon hubungi wali kelas.</p>
@endif
@endsection
