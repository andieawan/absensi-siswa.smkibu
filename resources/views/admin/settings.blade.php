@extends('layouts.app', ['title' => 'Pengaturan & Backup'])
@section('content')
@include('admin._tabs')
@if($pending)
<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt"><b>Ada {{ count($pending) }} pembaruan struktur database</b> dari versi aplikasi yang baru diunggah. Fitur baru (mis. modul BK, No. HP orang tua) baru bisa dipakai setelah diperbarui.
    <form method="post" action="{{ route('admin.migrate') }}" style="margin-top:8px" data-confirm="Perbarui database sekarang? Backup otomatis dibuat lebih dulu.">@csrf<button class="btn btn-sm btn-pri" data-busy="Memperbarui…">Perbarui Database Sekarang</button></form></span></div>
@endif
<div class="grid g2" style="align-items:start">
    <form method="post" action="{{ route('admin.settings.update') }}" class="card">@csrf @method('PUT')
        <h2>Pengaturan Sekolah</h2>
        <div class="field"><label>Nama sekolah</label><input type="text" name="school_name" value="{{ old('school_name', $settings->school_name) }}" required></div>
        <div class="fields">
            <div><label>Tahun ajaran</label><input type="text" name="tahun_ajaran" value="{{ old('tahun_ajaran', $settings->tahun_ajaran) }}" placeholder="2026/2027"></div>
            <div><label>Semester</label>@include('partials.select', ['name' => 'semester', 'options' => ['Ganjil' => 'Ganjil', 'Genap' => 'Genap'], 'selected' => old('semester', $settings->semester)])</div>
        </div>
        <div class="fields" style="margin-top:12px">
            <div><label>Nama Kepala Sekolah</label><input type="text" name="kepsek_nama" value="{{ old('kepsek_nama', $settings->kepsek_nama) }}"></div>
            <div><label>Nama Guru BK</label><input type="text" name="bk_nama" value="{{ old('bk_nama', $settings->bk_nama) }}"></div>
        </div>
        <div class="field" style="margin-top:12px"><label>Simpan backup (minggu)</label><input type="number" name="backup_retention_weeks" min="1" max="104" value="{{ old('backup_retention_weeks', $settings->backup_retention_weeks ?: 8) }}"></div>
        @if(\App\Support\DbUpdate::selfReady())
        @php
            $sc = \App\Services\StaffService::config();
        @endphp
        <fieldset class="fs" style="margin:16px 0 0;border:1px solid var(--line);border-radius:10px;padding:12px"><legend style="padding:0 6px;font-weight:700">Absen Mandiri Guru &amp; Staf</legend>
            <input type="hidden" name="staff_form" value="1">
            <label class="chk"><input type="checkbox" name="staff_mandiri" value="1" @checked(old('staff_form') ? old('staff_mandiri') : $sc['aktif'])> Aktifkan absen mandiri (guru &amp; staf absen sendiri dari HP)</label>
            <div class="fields" style="margin-top:10px">
                <div><label for="sj">Batas jam masuk</label><input id="sj" type="time" name="staff_jam_masuk" value="{{ old('staff_jam_masuk', $sc['batas']) }}"></div>
                <div><label for="sr">Radius dari sekolah (meter)</label><input id="sr" type="number" name="staff_radius" min="0" max="5000" value="{{ old('staff_radius', $sc['radius'] ?: '') }}" placeholder="kosong = tanpa cek lokasi"></div>
                <div><label for="sla">Lintang (latitude)</label><input id="sla" type="text" inputmode="decimal" name="staff_lat" value="{{ old('staff_lat', $sc['lat']) }}" placeholder="-8.1234567"></div>
                <div><label for="sln">Bujur (longitude)</label><input id="sln" type="text" inputmode="decimal" name="staff_lng" value="{{ old('staff_lng', $sc['lng']) }}" placeholder="113.1234567"></div>
            </div>
            <button type="button" class="btn btn-sm" style="margin-top:8px" data-geo-fill="#sla,#sln">📍 Pakai lokasi saya sekarang</button>
            <small class="mut" style="display:block;margin-top:6px">Buka halaman ini <b>saat berada di sekolah</b> lalu klik tombol di atas. Pengecekan lokasi memerlukan alamat <b>https://</b> dan izin lokasi di HP. Kosongkan radius bila tidak ingin memeriksa lokasi.</small>
        </fieldset>
        @endif
        <button class="btn btn-pri" style="margin-top:12px">Simpan Pengaturan</button>
    </form>
    <div class="card"><h2>Backup Database</h2>
        <p>Status terakhir: <span @class(['badge', 'b-ok' => $settings->last_backup_status === 'success', 'b-bad' => $settings->last_backup_status === 'failed'])>{{ $settings->last_backup_status ?? 'belum ada' }}</span>
            @if($settings->last_backup_date)<small>{{ $settings->last_backup_date }} UTC</small>@endif</p>
        <p class="mut" style="font-size:12px">Backup otomatis berjalan tiap 24 jam saat ada yang login, atau lewat penjadwal Laravel (<code>php artisan schedule:run</code> di cron).</p>
        <div class="row">
            <form method="post" action="{{ route('admin.backup') }}" class="inline">@csrf<button class="btn btn-pri">Backup Sekarang</button></form>
            <a class="btn" href="{{ route('admin.json') }}">Unduh Data (JSON)</a>
        </div>
        <div style="margin-top:14px">
            @forelse($files as $f)
                <div class="row" style="justify-content:space-between;border-top:1px solid var(--line2);padding:6px 0;font-size:12px"><span class="mono">{{ $f }}</span><a href="{{ route('admin.backup.download', $f) }}">Unduh</a></div>
            @empty
                <small class="mut">Belum ada berkas backup.</small>
            @endforelse
        </div>
    </div>
</div>
@endsection
