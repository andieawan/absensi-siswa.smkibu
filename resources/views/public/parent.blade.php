@extends('layouts.app', ['title' => 'Portal Wali Murid', 'bare' => true, 'width' => '720px'])
@push('head')<meta name="robots" content="noindex,nofollow">@endpush
@section('content')
@php($rc = fn ($r) => $r >= 90 ? 'ok' : ($r >= 80 ? 'warn' : 'bad'))
<div class="head"><div><h1>Portal Wali Murid</h1><small>{{ $settings->school_name }}</small></div></div>
@if($error)
    <div class="alert alert-error">{{ $error }}</div>
@else
    <div class="card"><h2>{{ $student->nama }}</h2><small class="mono">{{ $student->nis }}</small> · <small>{{ $student->schoolClass->name ?? '-' }}</small></div>
    <div class="grid g5" style="margin-bottom:16px">
        <div class="kpi"><small>Kehadiran</small><div class="n mono {{ $rc($stats['rate']) }}">{{ number_format($stats['rate'], 1) }}%</div></div>
        <div class="kpi"><small>Hadir</small><div class="n mono">{{ $stats['hadir'] }}</div></div>
        <div class="kpi"><small>Izin</small><div class="n mono">{{ $stats['izin'] }}</div></div>
        <div class="kpi"><small>Sakit</small><div class="n mono warn">{{ $stats['sakit'] }}</div></div>
        <div class="kpi"><small>Alpa</small><div class="n mono bad">{{ $stats['alpa'] }}</div></div>
    </div>
    @unless($stats['meets'])<div class="alert alert-error">Kehadiran ananda {{ $stats['rate'] }}% — di bawah batas minimal 85% untuk kenaikan kelas/kelulusan. Mohon segera berkoordinasi dengan wali kelas atau guru BK.</div>@endunless
    @if($attention['category'])<div class="alert alert-warn">Catatan perhatian: {{ \App\Services\Analytics::CATEGORIES[$attention['category']] }} (Alpa {{ $attention['alpa'] }}, Izin {{ $attention['izin'] }}, Sakit {{ $attention['sakit'] }}).</div>@endif
    @foreach($patterns as $a)<div class="alert alert-warn">Terdeteksi pola absen berkala pada hari {{ $a['day_of_week'] }} ({{ $a['count'] }}x): {{ implode(', ', $a['dates']) }}</div>@endforeach
    <div class="card card-tight"><div style="padding:14px 16px"><h2>30 Catatan Terakhir</h2></div>
        @if($recent->isEmpty())<div class="empty">Belum ada catatan kehadiran.</div>@else
        <table class="tbl"><thead><tr><th>Tanggal</th><th>Status</th><th>Catatan</th></tr></thead><tbody>
        @foreach($recent as $r)
            <tr><td>{{ \App\Support\Dates::human($r->tanggal) }}</td><td><span @class(['badge', 'b-ok' => $r->status === 'H', 'b-warn' => $r->status === 'S', 'b-bad' => $r->status === 'A'])>{{ \App\Models\Attendance::STATUS[$r->status] }}</span></td><td>{{ $r->notes ?? '-' }}</td></tr>
        @endforeach
        </tbody></table>@endif
    </div>
@endif
@endsection
