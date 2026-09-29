@extends('layouts.app', ['title' => 'Presensi Ketua Kelas', 'bare' => true, 'width' => '760px'])
@push('head')<meta name="robots" content="noindex,nofollow">@endpush
@section('content')
<div class="head"><div><h1>Presensi Harian — Ketua Kelas</h1><small>{{ $settings->school_name }}</small></div></div>
@if(! $t)
    <div class="alert alert-error">{{ $error }}</div>
    <div class="card"><p>Tautan tidak dapat dipakai. Minta Wali Kelas membuatkan tautan baru.</p></div>
@else
<div class="card">
    <div class="row" style="justify-content:space-between"><div><h2>{{ $class->name ?? '-' }}</h2><small>{{ $students->count() }} siswa aktif</small></div>
        @if($t->expiryMillis())<span class="badge b-warn">Berlaku sampai {{ \App\Support\Dates::local($t->expiryMillis()) }} WIB</span>@endif</div>
    <form method="get" action="{{ route('delegation', $token) }}" class="row" style="margin-top:12px">
        <div><label for="date">Tanggal presensi</label><input id="date" type="date" name="date" value="{{ $tanggal }}" min="{{ \Carbon\CarbonImmutable::parse(\App\Support\Dates::today())->subDays(7)->format('Y-m-d') }}" max="{{ \App\Support\Dates::today() }}" onchange="this.form.submit()"></div>
    </form>
    @if($existing->isNotEmpty())<p><span class="badge b-warn">Tanggal ini sudah pernah diisi — menyimpan lagi akan memperbarui.</span></p>@endif
</div>
<form method="post" action="{{ route('delegation', $token) }}" class="card card-tight">@csrf
    <input type="hidden" name="date" value="{{ $tanggal }}">
    <div class="row" style="padding:12px 14px;border-bottom:1px solid var(--line)"><button type="button" class="btn btn-sm" data-setall="H">Set Semua Hadir</button>
        <span id="recount" class="mono" style="font-size:12px">H <b data-c="H">0</b> · I <b data-c="I">0</b> · S <b data-c="S">0</b> · A <b data-c="A">0</b></span></div>
    @foreach($students as $i => $s)
        @include('partials.hisa', ['s' => $s, 'i' => $i, 'cur' => $existing[$s->id] ?? null])
    @endforeach
    <div class="sticky-save"><small>Data langsung diterima Wali Kelas.</small><button class="btn btn-pri" @disabled($students->isEmpty())>Kirim Presensi</button></div>
</form>
@endif
@endsection
