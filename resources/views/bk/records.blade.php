@extends('layouts.app', ['title' => $m['label'].' · BK'])
@section('content')
@php
    $me = auth()->user();
    $counselor = \App\Services\BkService::isCounselor($me);
    $pre = $preselect ? \App\Models\Student::find($preselect) : null;
@endphp
<div class="head"><div><h1>{{ $m['icon'] }} {{ $m['label'] }}</h1><small>{{ $m['desc'] }}</small></div></div>
@include('bk._tabs')

@if($writable->isNotEmpty())
<details class="card" @if($errors->any() || session('error') || ($pre && $writable->contains('id', $pre->class_id))) open @endif>
    <summary style="font-weight:700">+ Catat {{ $m['label'] }}</summary>
    <form method="post" action="{{ route('bk.records.store', $jenis) }}" enctype="multipart/form-data" style="margin-top:12px" data-unsaved>@csrf
        @php($selClass = (int) old('pick_class', $pre->class_id ?? ($writable->count() === 1 ? $writable->first()->id : 0)))
        <div class="fields">
            <div><label for="pick-class">Kelas</label>
                <select id="pick-class" name="pick_class" data-pick-class="#pick-student">
                    @if($writable->count() > 1)<option value="">— pilih kelas —</option>@endif
                    @foreach($writable as $c)<option value="{{ $c->id }}" @selected($selClass === $c->id)>{{ $c->name }}</option>@endforeach
                </select></div>
            <div style="grid-column:span 2"><label for="pick-student">Nama siswa</label>
                <select id="pick-student" name="student_id" required>
                    <option value="">— pilih siswa —</option>
                    @foreach($writable as $c)
                        @if(isset($pickStudents[$c->id]))
                        <optgroup label="{{ $c->name }}" data-class="{{ $c->id }}">
                            @foreach($pickStudents[$c->id] as $s)<option value="{{ $s->id }}" @selected((int) old('student_id', $preselect) === $s->id)>{{ $s->nama }} ({{ $s->nis }})</option>@endforeach
                        </optgroup>
                        @endif
                    @endforeach
                </select></div>
        </div>
        <div style="margin-top:12px">@include('bk._fields', ['rec' => null, 'px' => 'n-'])</div>
        <button class="btn btn-pri" data-busy="Menyimpan…" style="margin-top:6px">Simpan</button>
    </form>
</details>
@elseif(! $counselor && ! $m['wali_tulis'])
    <p class="hint">{{ $m['label'] }} dicatat oleh Guru BK. Anda dapat melihat catatan {{ \App\Services\BkService::seesAll($me) ? 'semua kelas' : 'siswa kelas Anda' }}.</p>
@endif

<form method="get" action="{{ route('bk.records', $jenis) }}" class="card filter-card">
    <div class="fields">
        <div><label for="q">Cari</label><input id="q" type="search" name="q" value="{{ $q }}" placeholder="Nama / NIS / {{ strtolower($m['judul'][0]) }}"></div>
        @if($filterClasses->count() > 1)
            <div><label for="fc">Kelas</label><select id="fc" name="class" data-auto><option value="">Semua kelas</option>@foreach($filterClasses as $c)<option value="{{ $c->id }}" @selected($fc === $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        @endif
        @if($m['status'])
            <div><label for="fs">Status</label><select id="fs" name="status" data-auto><option value="">Semua</option>@foreach(\App\Support\BkModules::STATUS as $o)<option @selected($status === $o)>{{ $o }}</option>@endforeach</select></div>
        @endif
        <div style="align-self:end" class="row"><button class="btn">Cari</button>@if($q !== '' || $fc || $status || $preselect)<a class="btn btn-sm" href="{{ route('bk.records', $jenis) }}">Reset</a>@endif</div>
    </div>
    @if($pre)<input type="hidden" name="siswa" value="{{ $pre->id }}"><p class="hint" style="margin:8px 0 0">Menampilkan catatan <b>{{ $pre->nama }}</b> saja.</p>@endif
</form>

<div class="card card-tight" id="daftar-bk" data-swap>
    @forelse($records as $r)
        @php($masked = \App\Services\BkService::isMasked($me, $r))
        @php($canEdit = \App\Services\BkService::canEdit($me, $r))
        <article class="bk-item">
            <div class="bk-top">
                <div class="nm"><b>{{ $r->student->nama ?? '(siswa dihapus)' }}</b>
                    <small>{{ $r->schoolClass->name ?? '-' }} · {{ \App\Support\Dates::human($r->tanggal) }}@if($r->author) · dicatat {{ $r->author->nama }}@endif</small></div>
                <div class="row" style="gap:6px">
                    @if($r->kategori && ! $masked)<span @class(['badge', 'b-ok' => in_array($r->kategori, ['Ringan', 'Sekolah', 'Kecamatan']), 'b-warn' => $r->kategori === 'Sedang', 'b-bad' => $r->kategori === 'Berat'])>{{ $r->kategori }}</span>@endif
                    @if($r->rahasia)<span class="badge">🔒 Rahasia</span>@endif
                    @if($r->status)<span @class(['badge', 'b-warn' => $r->status === 'Proses', 'b-ok' => $r->status === 'Selesai'])>{{ $r->status }}</span>@endif
                </div>
            </div>
            @if($masked)
                <p class="mut" style="margin:6px 0 0">🔒 Kasus rahasia — ditangani Guru BK. Detail tidak ditampilkan.</p>
            @else
                <p style="margin:6px 0 0"><b>{{ $r->judul }}</b>@if($r->x('peringkat')) — {{ $r->x('peringkat') }}@endif</p>
                @php($extras = collect($m['extra'])->except('peringkat')->filter(fn ($d, $k) => $r->x($k) !== ''))
                @if($extras->isNotEmpty())<small class="mut">@foreach($extras as $k => [$label]){{ $label }}: {{ $r->x($k) }}@if(! $loop->last) · @endif @endforeach</small>@endif
                @if($r->uraian)<p class="bk-txt">{{ $r->uraian }}</p>@endif
                @if($r->tindak_lanjut)<p class="bk-txt"><b>{{ $m['tindak'] }}:</b> {{ $r->tindak_lanjut }}</p>@endif
            @endif
            <div class="row" style="margin-top:8px;gap:6px">
                @if($r->student)<a class="btn btn-sm" href="{{ route('students', ['id' => $r->student_id]) }}">Riwayat siswa</a>@endif
                @if(! $masked && $r->berkas_path)<a class="btn btn-sm" target="_blank" href="{{ route('bk.records.file', [$jenis, $r]) }}">📎 Lihat scan</a>@endif
                @if($r->student && ! $masked)@include('letters._menu', ['student' => $r->student])@endif
                @if($canEdit && $r->student)
                    @php($wa = \App\Support\WhatsApp::link($r->student->telp_ortu, \App\Support\WhatsApp::recordMessage($r, $me->nama)))
                    @if($wa)<a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="{{ $wa }}">WhatsApp ortu</a>@else<span class="btn btn-sm" aria-disabled="true" title="Nomor HP orang tua belum diisi di Data Siswa">No. HP ortu belum ada</span>@endif
                @endif
            </div>
            @if($canEdit)
                <details class="more"><summary>Ubah / hapus</summary>
                    <form method="post" action="{{ route('bk.records.update', [$jenis, $r]) }}" enctype="multipart/form-data">@csrf @method('PUT')
                        @include('bk._fields', ['rec' => $r, 'px' => 'e'.$r->id.'-'])
                        <button class="btn btn-sm btn-pri" data-busy="Menyimpan…">Simpan perubahan</button>
                    </form>
                    <form method="post" action="{{ route('bk.records.destroy', [$jenis, $r]) }}" data-confirm="Hapus catatan ini? Tidak dapat dibatalkan." style="margin-top:8px">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Hapus catatan</button></form>
                </details>
            @endif
        </article>
    @empty
        <div class="empty">Belum ada catatan {{ strtolower($m['label']) }}@if($q !== '' || $fc || $status) yang cocok dengan pencarian @endif.</div>
    @endforelse
    @include('partials.pager', ['p' => $records])
</div>
@endsection
