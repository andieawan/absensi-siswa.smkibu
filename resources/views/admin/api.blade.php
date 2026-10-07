@extends('layouts.app', ['title' => 'Integrasi API'])
@section('content')
@include('admin._tabs')
@php
    $wib = fn ($t) => $t ? rescue(fn () => \Carbon\CarbonImmutable::parse($t.' UTC')->setTimezone(\App\Support\Dates::tz())->format('Y-m-d H:i'), $t, false) : '-';
@endphp
@if(! $ready)
    <div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Fitur ini butuh pembaruan database. Buka <a href="{{ route('admin.settings') }}">Pengaturan → Perbarui Database Sekarang</a>.</span></div>
@else
@if(session('new_api_key'))
<div class="card" style="border:2px solid var(--pri,#0b6b4f)">
    <b>Kunci API untuk “{{ session('new_api_name') }}”</b>
    <p class="hint" style="margin:6px 0">Salin dan simpan sekarang. Kunci <b>tidak akan ditampilkan lagi</b> (di server hanya tersimpan versi hash-nya).</p>
    <code class="link" id="newkey" style="word-break:break-all">{{ session('new_api_key') }}</code>
    <div class="row" style="margin-top:8px"><button type="button" class="btn btn-sm" data-copy="#newkey">Salin Kunci</button></div>
</div>
@endif
<details class="card" @if($errors->any()) open @endif><summary style="cursor:pointer;font-weight:700">+ Buat Kunci API Baru</summary>
    <form method="post" action="{{ route('admin.api.store') }}" style="margin-top:12px">@csrf
        <div class="fields">
            <div><label>Nama aplikasi</label><input type="text" name="name" value="{{ old('name') }}" maxlength="100" placeholder="mis. Website Sekolah" required></div>
            <div><label>Batas permintaan / menit</label><input type="number" name="rate_limit" value="{{ old('rate_limit', 60) }}" min="1" max="6000" required></div>
        </div>
        <div class="field" style="margin-top:12px"><label>Izin (hanya baca)</label>@include('admin._checks', ['name' => 'scopes', 'items' => $scopes, 'sel' => old('scopes', [])])
            <p class="hint" style="margin:4px 0 0">Beri izin seperlunya. Data nilai dan catatan BK tidak tersedia lewat API.</p></div>
        <button class="btn btn-pri">Buat Kunci</button>
    </form>
</details>

<div class="card card-tight"><div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Aplikasi</th><th>Kunci</th><th>Izin</th><th>Batas/mnt</th><th>Terakhir dipakai</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($keys as $k)
    <tr>
        <td data-label="Aplikasi"><b>{{ $k->name }}</b><br><small class="mut">dibuat {{ $wib($k->created_at) }}</small></td>
        <td class="mono" data-label="Kunci">{{ $k->key_prefix }}…</td>
        <td style="font-size:12px" data-label="Izin">{{ implode(', ', $k->scopes ?? []) }}</td>
        <td data-label="Batas/mnt">{{ $k->rate_limit }}</td>
        <td style="font-size:12.5px" data-label="Terakhir dipakai">{{ $wib($k->last_used_at) }}@if($k->last_ip)<br><small class="mut">{{ $k->last_ip }}</small>@endif</td>
        <td data-label="Status"><span @class(['badge', 'b-ok' => $k->is_active, 'b-bad' => ! $k->is_active])>{{ $k->is_active ? 'Aktif' : 'Dicabut' }}</span></td>
        <td class="r">
            @if($k->is_active)
                <form method="post" action="{{ route('admin.api.revoke', $k) }}" class="inline" data-confirm="Cabut kunci “{{ $k->name }}”? Aplikasi tersebut langsung tidak bisa mengakses API.">@csrf<button class="btn btn-sm btn-danger">Cabut</button></form>
            @else
                <form method="post" action="{{ route('admin.api.destroy', $k) }}" class="inline" data-confirm="Hapus kunci yang sudah dicabut ini dari daftar?">@csrf @method('DELETE')<button class="btn btn-sm">Hapus</button></form>
            @endif
        </td>
    </tr>
@empty
    <tr><td colspan="7" class="empty">Belum ada kunci API.</td></tr>
@endforelse
</tbody></table></div></div>

<div class="card">
    <b>Cara memakai</b>
    <p class="hint" style="margin:6px 0">Alamat dasar: <code>{{ $base }}</code> — kirim kunci di header <code>Authorization: Bearer &lt;kunci&gt;</code>.</p>
    <p class="hint" style="margin:6px 0">Endpoint: <code>/ping</code>, <code>/kelas</code>, <code>/mapel</code>, <code>/siswa</code>, <code>/siswa/{id}/rekap</code>, <code>/kehadiran</code>. Spesifikasi lengkap (OpenAPI): <a href="{{ $base }}/openapi.json" target="_blank" rel="noopener">{{ $base }}/openapi.json</a>.</p>
    <p class="hint" style="margin:6px 0">Penggunaan kunci tercatat di Log Aktivitas (satu baris per jam per kunci; waktu & IP terakhir ada di tabel di atas).</p>
</div>
@endif
@endsection
