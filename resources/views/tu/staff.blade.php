@extends('layouts.app', ['title' => 'Absensi Guru & Staf'])
@section('content')
@include('tu._tabs', ['h' => 'Absensi Guru & Staf', 'sub' => \App\Support\Dates::human($tanggal)])
@php
    $today = \App\Support\Dates::today();
    $shift = fn (int $d) => gmdate('Y-m-d', strtotime($tanggal.' UTC') + $d * 86400);
    $url = fn (array $o = []) => route('tu.staff', array_merge(['date' => $tanggal], $o));
    $can = $canWrite && ! $locked;
@endphp
<form method="get" action="{{ route('tu.staff') }}" class="card filter-card">
    <div class="fields"><div class="date-pick"><label for="date">Tanggal</label>
        <div class="date-row">
            <a class="btn btn-icon" href="{{ $url(['date' => $shift(-1)]) }}" aria-label="Hari sebelumnya">‹</a>
            <input id="date" type="date" name="date" value="{{ $tanggal }}" max="{{ $today }}" onchange="this.form.submit()">
            @if($tanggal < $today)<a class="btn btn-icon" href="{{ $url(['date' => $shift(1)]) }}" aria-label="Hari berikutnya">›</a>@else<span class="btn btn-icon" aria-disabled="true">›</span>@endif
            @if($tanggal !== $today)<a class="btn btn-sm" href="{{ $url(['date' => $today]) }}">Hari ini</a>@endif
        </div></div>
        <div style="align-self:end"><a class="btn btn-sm" href="{{ route('tu.staff.recap', ['bulan' => substr($tanggal, 0, 7)]) }}">Rekap bulanan</a></div>
    </div>
</form>

@if($locked && $canWrite)<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">🔒 Absensi lebih dari 7 hari terkunci. Hanya Administrator yang dapat mengubah.</span></div>@endif
@if($existing->isNotEmpty())<div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt"><b>Sudah diisi</b> untuk tanggal ini. Menyimpan lagi hanya memperbarui.</span></div>@endif

@if($staff->isEmpty())
    <div class="card"><div class="empty">Belum ada akun guru/staf aktif. Tambahkan di Admin → Akun Guru (peran Guru, Guru BK, Kepala Sekolah, atau TU).</div></div>
@elseif($can)
<form method="post" action="{{ route('tu.staff.store') }}" class="card card-tight" data-unsaved>@csrf <input type="hidden" name="date" value="{{ $tanggal }}">
    <div class="list-tools">
        <button type="button" class="btn btn-sm" data-setall="H">✓ Tandai semua Hadir</button>
        <span class="legend" aria-hidden="true"><i class="lg H">H</i>Hadir <i class="lg I">I</i>Izin <i class="lg S">S</i>Sakit <i class="lg A">A</i>Alpa <i class="lg D">D</i>Dinas</span>
    </div>
    @foreach($staff as $i => $u)
        @php($cur = $existing[$u->id] ?? null)
        @php($st = old("status.{$u->id}", $cur->status ?? 'H'))
        @php($note = old("notes.{$u->id}", $cur->notes ?? ''))
        <div class="stu stu-att" data-row>
            <span class="no mono">{{ $i + 1 }}</span>
            <div class="nm"><b>{{ $u->nama }}</b><small>{{ $u->roleLabel() }}@include('tu._jam', ['cur' => $cur])</small></div>
            <div class="hisa" role="radiogroup" aria-label="Status kehadiran {{ $u->nama }}">
                @foreach(\App\Services\StaffService::STATUS as $k => $lbl)
                    <label title="{{ $lbl }}"><input type="radio" name="status[{{ $u->id }}]" value="{{ $k }}" data-status="{{ $k }}" @checked($st === $k) aria-label="{{ $lbl }}"><span class="{{ $k }}" aria-hidden="true">{{ $k }}</span></label>
                @endforeach
            </div>
            <button type="button" @class(['note-toggle', 'has-note' => $note !== '']) data-note-toggle aria-expanded="{{ $note !== '' ? 'true' : 'false' }}" aria-controls="snote-{{ $u->id }}" title="Catatan">✎<span class="sr">Catatan untuk {{ $u->nama }}</span></button>
            <input id="snote-{{ $u->id }}" @class(['note', 'note-open' => $note !== '']) type="text" name="notes[{{ $u->id }}]" value="{{ $note }}" placeholder="Catatan, mis. surat dokter / tugas dinas" maxlength="200" aria-label="Catatan {{ $u->nama }}">
        </div>
    @endforeach
    <div class="sticky-save">
        <div id="recount" class="recount" aria-live="polite"><span class="rc H">H <b data-c="H">0</b></span><span class="rc I">I <b data-c="I">0</b></span><span class="rc S">S <b data-c="S">0</b></span><span class="rc A">A <b data-c="A">0</b></span><span class="rc D">D <b data-c="D">0</b></span></div>
        <button class="btn btn-pri" data-busy="Menyimpan…">Simpan Absensi</button>
    </div>
</form>
@else
<div class="card card-tight">
    @foreach($staff as $i => $u)
        @php($cur = $existing[$u->id] ?? null)
        <div class="stu"><span class="no mono">{{ $i + 1 }}</span><div class="nm"><b>{{ $u->nama }}</b><small>{{ $u->roleLabel() }}@include('tu._jam', ['cur' => $cur])@if($cur?->notes) · {{ $cur->notes }}@endif</small></div>
            @if($cur)<span class="lg {{ $cur->status }}" title="{{ \App\Services\StaffService::STATUS[$cur->status] }}">{{ $cur->status }}</span>@else<span class="badge">Belum diisi</span>@endif</div>
    @endforeach
</div>
@endif
@endsection
