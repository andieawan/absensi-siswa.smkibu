@extends('layouts.app', ['title' => $arah === 'masuk' ? 'Surat Masuk' : 'Surat Keluar'])
@section('content')
@include('tu._tabs', ['h' => $arah === 'masuk' ? 'Register Surat Masuk' : 'Register Surat Keluar', 'sub' => $arah === 'masuk' ? 'Catat surat yang diterima sekolah, disposisi, dan scan-nya.' : 'Nomor surat dibuat otomatis (contoh 012/KET/'.config('absensi.kode_surat').'/X/'.substr($today, 0, 4).').'])

@if($canWrite)
<div class="grid g2" style="align-items:start">
    <details class="card" @if($errors->any() || session('error')) open @endif><summary style="font-weight:700">+ Catat Surat {{ $arah === 'masuk' ? 'Masuk' : 'Keluar (biasa)' }}</summary>
        <form method="post" action="{{ route('tu.surat.store', $arah) }}" enctype="multipart/form-data" style="margin-top:12px">@csrf
            @include('tu._surat-fields', ['rec' => null, 'px' => 'n-'])
            <button class="btn btn-pri" data-busy="Menyimpan…">Simpan</button>
        </form>
    </details>
    @if($arah === 'keluar')
    <details class="card"><summary style="font-weight:700">✉ Buat Surat Keterangan / Dispensasi / Izin Siswa</summary>
        <form method="post" action="{{ route('tu.keterangan') }}" style="margin-top:12px" data-jenis-form>@csrf
            <div class="fields">
                <div><label for="kt-jenis">Jenis surat</label><select id="kt-jenis" name="jenis" data-jenis-switch>@foreach(\App\Services\TuService::KETERANGAN as $k => $l)<option value="{{ $k }}" @selected(old('jenis') === $k)>{{ $l }}</option>@endforeach</select></div>
                <div><label for="kt-kelas">Kelas</label><select id="kt-kelas" data-pick-class="#kt-siswa"><option value="">— pilih kelas —</option>@foreach($classes as $c)@if(isset($pickStudents[$c->id]))<option value="{{ $c->id }}">{{ $c->name }}</option>@endif @endforeach</select></div>
                <div style="grid-column:span 2"><label for="kt-siswa">Nama siswa</label><select id="kt-siswa" name="student_id" required><option value="">— pilih siswa —</option>
                    @foreach($classes as $c)@if(isset($pickStudents[$c->id]))<optgroup label="{{ $c->name }}" data-class="{{ $c->id }}">@foreach($pickStudents[$c->id] as $s)<option value="{{ $s->id }}" @selected((int) old('student_id') === $s->id)>{{ $s->nama }} ({{ $s->nis }})</option>@endforeach</optgroup>@endif @endforeach</select></div>
                <div><label for="kt-tgl">Tanggal surat</label><input id="kt-tgl" type="date" name="tanggal" value="{{ old('tanggal', $today) }}" required></div>
                <div data-jenis="aktif"><label for="kt-kep">Untuk keperluan</label><input id="kt-kep" type="text" name="keperluan" value="{{ old('keperluan') }}" placeholder="mis. pengajuan beasiswa"></div>
                <div data-jenis="pindah"><label for="kt-sek">Sekolah tujuan</label><input id="kt-sek" type="text" name="sekolah_tujuan" value="{{ old('sekolah_tujuan') }}"></div>
                <div data-jenis="dispensasi izin"><label for="kt-mulai">Mulai tanggal</label><input id="kt-mulai" type="date" name="mulai" value="{{ old('mulai', $today) }}"></div>
                <div data-jenis="dispensasi izin"><label for="kt-sampai">Sampai tanggal <small>(kosong = 1 hari)</small></label><input id="kt-sampai" type="date" name="sampai" value="{{ old('sampai') }}"></div>
                <div data-jenis="pindah dispensasi izin" style="grid-column:span 2"><label for="kt-al">Alasan</label><input id="kt-al" type="text" name="alasan" value="{{ old('alasan') }}" placeholder="mis. mengikuti lomba tingkat kabupaten"></div>
            </div>
            <button class="btn btn-pri" style="margin-top:12px" data-busy="Membuat…">Buat Surat &amp; Nomor Otomatis</button>
        </form>
    </details>
    @endif
</div>
@endif

<form method="get" action="{{ route('tu.surat', $arah) }}" class="card filter-card">
    <div class="fields">
        <div><label for="q">Cari</label><input id="q" type="search" name="q" value="{{ $q }}" placeholder="Nomor / perihal / {{ $arah === 'masuk' ? 'pengirim' : 'tujuan' }}"></div>
        <div><label for="th">Tahun</label><select id="th" name="tahun" data-auto>@foreach($years as $y)<option @selected($y === $tahun)>{{ $y }}</option>@endforeach</select></div>
        @if($arah === 'masuk')<div><label for="fs">Status</label><select id="fs" name="status" data-auto><option value="">Semua</option>@foreach(\App\Services\TuService::STATUS_MASUK as $o)<option @selected($status === $o)>{{ $o }}</option>@endforeach</select></div>@endif
        <div style="align-self:end"><button class="btn">Cari</button></div>
    </div>
</form>

<div class="card card-tight">
    @forelse($list as $s)
        <article class="bk-item">
            <div class="bk-top">
                <div class="nm"><b>{{ $s->perihal }}</b>
                    <small>Agenda <span class="mono">{{ $s->urut }}/{{ $s->tahun }}</span>@if($s->nomor) · No. <span class="mono">{{ $s->nomor }}</span>@endif · {{ \App\Support\Dates::human($s->tanggal) }}</small>
                    <small>{{ $arah === 'masuk' ? 'Dari' : 'Kepada' }}: {{ $s->pihak }}@if($s->student) · siswa: {{ $s->student->nama }}@endif</small></div>
                <div class="row" style="gap:6px">
                    @if($s->jenis !== 'biasa')<span class="badge">{{ \App\Services\TuService::KETERANGAN[$s->jenis] ?? $s->jenis }}</span>@endif
                    @if($s->status)<span @class(['badge', 'b-warn' => $s->status === 'Diproses', 'b-bad' => $s->status === 'Baru', 'b-ok' => $s->status === 'Selesai'])>{{ $s->status }}</span>@endif
                </div>
            </div>
            @if($s->isi && $s->jenis === 'biasa')<p class="bk-txt">{{ \Illuminate\Support\Str::limit($s->isi, 220) }}</p>@endif
            @if($s->disposisi)<p class="bk-txt"><b>Disposisi:</b> {{ $s->disposisi }}</p>@endif
            <div class="row" style="margin-top:8px;gap:6px">
                @if($arah === 'keluar')<a class="btn btn-sm" target="_blank" href="{{ route('tu.surat.print', $s) }}">🖨 Cetak</a>@endif
                @if($s->berkas_path)<a class="btn btn-sm" target="_blank" href="{{ route('tu.surat.file', $s) }}">📎 Lihat scan</a>@endif
            </div>
            @if($canWrite)
            <details class="more"><summary>Ubah / hapus</summary>
                <form method="post" action="{{ route('tu.surat.update', $s) }}" enctype="multipart/form-data">@csrf @method('PUT')
                    @include('tu._surat-fields', ['rec' => $s, 'px' => 'e'.$s->id.'-'])
                    <button class="btn btn-sm btn-pri" data-busy="Menyimpan…">Simpan perubahan</button>
                </form>
                <form method="post" action="{{ route('tu.surat.destroy', $s) }}" data-confirm="Hapus surat ini dari register?" style="margin-top:8px">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Hapus surat</button></form>
            </details>
            @endif
        </article>
    @empty
        <div class="empty">Belum ada surat {{ $arah }} tahun {{ $tahun }}.</div>
    @endforelse
    @include('partials.pager', ['p' => $list])
</div>
@endsection
