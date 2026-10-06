@extends('layouts.app', ['title' => 'Info Kehadiran Ananda', 'bare' => true, 'width' => '720px'])
@push('head')<meta name="robots" content="noindex,nofollow">@endpush
@section('content')
<div class="head"><div><h1>Info Kehadiran Ananda</h1><small>{{ $settings->school_name }}</small></div></div>
@if($error)
    <div class="alert alert-error">{{ $error }}</div>
@else
    <div class="card">
        <h2>Kelas {{ $class->name ?? '-' }}</h2>
        @if($wali)<small>Wali kelas: {{ $wali->nama }}</small>@endif
    </div>
    @if($student)
        @php($todayRow = $rows->firstWhere('tanggal', $today))
        <div class="card">
            <h2>{{ $student->nama }}</h2><small class="mono">NIS {{ $student->nis }}</small>
            <div style="margin-top:10px"><small>Hari ini ({{ \App\Support\Dates::human($today) }})</small><br>
                @if($todayRow)
                    <span @class(['badge', 'b-ok' => $todayRow->status === 'H', 'b-warn' => in_array($todayRow->status, ['I', 'S'], true), 'b-bad' => $todayRow->status === 'A']) style="font-size:15px">{{ \App\Models\Attendance::STATUS[$todayRow->status] ?? $todayRow->status }}</span>
                @else
                    <span class="mut">Belum dicatat wali kelas.</span>
                @endif
            </div>
        </div>
        <div class="card card-tight"><div style="padding:14px 16px"><h2>14 hari terakhir</h2></div>
            @if($rows->isEmpty())<div class="empty">Belum ada catatan kehadiran.</div>@else
            <table class="tbl"><thead><tr><th>Tanggal</th><th>Status</th></tr></thead><tbody>
            @foreach($rows as $r)
                <tr><td>{{ \App\Support\Dates::human($r->tanggal) }}</td><td><span @class(['badge', 'b-ok' => $r->status === 'H', 'b-warn' => in_array($r->status, ['I', 'S'], true), 'b-bad' => $r->status === 'A'])>{{ \App\Models\Attendance::STATUS[$r->status] ?? $r->status }}</span></td></tr>
            @endforeach
            </tbody></table>@endif
        </div>
        <p><a class="btn btn-sm" href="{{ route('board', $token) }}">Cek anak lain</a></p>
    @else
        <div class="card">
            <h2>Cek kehadiran ananda</h2>
            <p class="mut" style="font-size:13px">Masukkan NIS ananda dan 4 digit terakhir nomor HP orang tua/wali yang terdaftar di sekolah.</p>
            @if($fail)<div class="alert alert-error">{{ $fail }}</div>@endif
            <form method="post" action="{{ route('board.check', $token) }}">
                @csrf
                <div class="field"><label for="nis">NIS ananda</label><input style="width:100%" id="nis" name="nis" value="{{ $nis }}" inputmode="numeric" autocomplete="off" required maxlength="64"></div>
                <div class="field"><label for="pin">4 digit terakhir nomor HP orang tua</label><input style="width:100%" id="pin" name="pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="off" required placeholder="mis. 6789"></div>
                <button class="btn btn-pri">Lihat Kehadiran</button>
            </form>
        </div>
    @endif
    <p class="mut" style="font-size:12px">Bila ananda tidak masuk tetapi belum ada keterangan, mohon hubungi wali kelas.</p>
@endif
@endsection
