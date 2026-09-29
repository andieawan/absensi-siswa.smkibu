@extends('layouts.app', ['title' => 'Log Aktivitas'])
@section('content')
@include('admin._tabs')
<form method="get" action="{{ route('admin.logs') }}" class="card">
    <div class="fields">
        <div><label>Modul</label><select name="modul" data-auto><option value="">Semua</option>@foreach($modules as $m)<option @selected($m === $mod)>{{ $m }}</option>@endforeach</select></div>
        <div><label>Cari</label><input type="search" name="q" value="{{ $q }}" placeholder="aksi, pelaku, detail"></div>
        <div><button class="btn">Cari</button></div>
    </div>
</form>
<div class="card card-tight"><div class="scroll"><table class="tbl"><thead><tr><th>Waktu (WIB)</th><th>Aksi</th><th>Modul</th><th>Pelaku</th><th>Detail</th></tr></thead><tbody>
@forelse($logs as $r)
    <tr><td class="mono" style="white-space:nowrap">{{ rescue(fn () => \Carbon\CarbonImmutable::parse($r->timestamp)->setTimezone(\App\Support\Dates::tz())->format('Y-m-d H:i'), $r->timestamp, false) }}</td>
        <td><b>{{ $r->action }}</b></td><td>{{ $r->module }}</td><td>{{ $r->actor }}</td><td style="font-size:12px">{{ $r->details }}</td></tr>
@empty
    <tr><td colspan="5" class="empty">Tidak ada log.</td></tr>
@endforelse
</tbody></table></div>@include('partials.pager', ['p' => $logs])</div>
<small class="mut">Menyimpan maksimal 500 log terbaru.</small>
@endsection
