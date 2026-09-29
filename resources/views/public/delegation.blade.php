@extends('layouts.app', ['title' => 'Presensi Ketua Kelas', 'bare' => true, 'width' => '760px'])
@push('head')<meta name="robots" content="noindex,nofollow">@endpush
@section('content')
<div class="head"><div><h1>Presensi Harian Kelas</h1><small>Diisi oleh Ketua Kelas · {{ $settings->school_name }}</small></div></div>
@if(! $t)
    <div class="alert alert-error"><span class="alert-ic" aria-hidden="true">✕</span><span class="alert-txt">{{ $error }}</span></div>
    <div class="card"><div class="empty"><b>Tautan tidak dapat dipakai.</b><br>Minta Wali Kelas membuatkan tautan baru dari menu Absensi → Riwayat &amp; Ketua Kelas.</div></div>
@else
@php($today = \App\Support\Dates::today())
@php($min = \Carbon\CarbonImmutable::parse($today)->subDays(7)->format('Y-m-d'))
<div class="card">
    <div class="row" style="justify-content:space-between;align-items:flex-start">
        <div><h2>{{ $class->name ?? '-' }}</h2><small>{{ $students->count() }} siswa aktif</small></div>
        @if($t->expiryMillis())<span class="badge b-warn">⏱ Berlaku sampai {{ \App\Support\Dates::local($t->expiryMillis()) }} WIB</span>@endif
    </div>
    <ol class="steps">
        <li>Pastikan tanggal sudah benar.</li>
        <li>Semua siswa awalnya <b>Hadir</b>. Ubah hanya yang <b>Izin</b>, <b>Sakit</b>, atau <b>Alpa</b>.</li>
        <li>Tekan <b>Kirim Presensi</b> di bawah.</li>
    </ol>
    <form method="get" action="{{ route('delegation', $token) }}" style="margin-top:12px">
        <label for="date">Tanggal presensi</label>
        <div class="date-row">
            <input id="date" type="date" name="date" value="{{ $tanggal }}" min="{{ $min }}" max="{{ $today }}" onchange="this.form.submit()">
            @if($tanggal !== $today)<a class="btn btn-sm" href="{{ route('delegation', [$token, 'date' => $today]) }}">Hari ini</a>@else<span class="badge b-ok">Hari ini</span>@endif
        </div>
    </form>
    @if($existing->isNotEmpty())<div class="alert alert-info" style="margin:12px 0 0"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">Tanggal ini <b>sudah pernah diisi</b>. Mengirim lagi akan memperbarui data.</span></div>@endif
</div>
<form method="post" action="{{ route('delegation', $token) }}" class="card card-tight" data-unsaved>@csrf
    <input type="hidden" name="date" value="{{ $tanggal }}">
    <div class="list-tools">
        <button type="button" class="btn btn-sm" data-setall="H">✓ Tandai semua Hadir</button>
        <span class="legend" aria-hidden="true"><i class="lg H">H</i>Hadir <i class="lg I">I</i>Izin <i class="lg S">S</i>Sakit <i class="lg A">A</i>Alpa</span>
    </div>
    @foreach($students as $i => $s)
        @include('partials.hisa', ['s' => $s, 'i' => $i, 'cur' => $existing[$s->id] ?? null])
    @endforeach
    <div class="sticky-save">
        <div id="recount" class="recount" aria-live="polite"><span class="rc H">H <b data-c="H">0</b></span><span class="rc I">I <b data-c="I">0</b></span><span class="rc S">S <b data-c="S">0</b></span><span class="rc A">A <b data-c="A">0</b></span></div>
        <button class="btn btn-pri" data-busy="Mengirim…" @disabled($students->isEmpty())>Kirim Presensi</button>
    </div>
</form>
@endif
@endsection
