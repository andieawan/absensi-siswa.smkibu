@extends('layouts.app', ['title' => 'Pengaturan & Backup'])
@section('content')
@include('admin._tabs')
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
        <button class="btn btn-pri">Simpan Pengaturan</button>
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
