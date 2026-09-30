@extends('layouts.app', ['title' => 'Mutasi Siswa'])
@section('content')
@include('tu._tabs', ['h' => 'Mutasi Siswa', 'sub' => 'Siswa pindahan masuk dan siswa yang pindah/berhenti/keluar. Status siswa ikut berubah otomatis.'])

@if($canWrite)
<div class="grid g2" style="align-items:start">
    <details class="card" @if($errors->any() && old('nis')) open @endif><summary style="font-weight:700">+ Siswa Masuk (pindahan / baru)</summary>
        <form method="post" action="{{ route('tu.mutasi.masuk') }}" style="margin-top:12px">@csrf
            <div class="fields">
                <div><label for="m-nis">NIS</label><input id="m-nis" type="text" name="nis" value="{{ old('nis') }}" required></div>
                <div><label for="m-nama">Nama siswa</label><input id="m-nama" type="text" name="nama" value="{{ old('nama') }}" required></div>
                <div><label for="m-jk">JK</label>@include('partials.select', ['name' => 'jk', 'options' => ['L' => 'Laki-laki', 'P' => 'Perempuan'], 'selected' => old('jk')])</div>
                <div><label for="m-kelas">Masuk ke kelas</label>@include('partials.select', ['name' => 'class_id', 'options' => $classes->pluck('name', 'id')->all(), 'selected' => old('class_id')])</div>
                <div><label for="m-tgl">Tanggal masuk</label><input id="m-tgl" type="date" name="tanggal" value="{{ old('tanggal', $today) }}" max="{{ $today }}" required></div>
                <div><label for="m-asal">Sekolah asal</label><input id="m-asal" type="text" name="sekolah" value="{{ old('sekolah') }}" placeholder="isi - bila siswa baru" required></div>
                <div><label for="m-no">No. surat pindah <small>(opsional)</small></label><input id="m-no" type="text" name="nomor_surat" value="{{ old('nomor_surat') }}"></div>
                <div><label for="m-al">Alasan <small>(opsional)</small></label><input id="m-al" type="text" name="alasan" value="{{ old('alasan') }}"></div>
                <div><label for="m-ortu">Nama orang tua <small>(opsional)</small></label><input id="m-ortu" type="text" name="nama_ortu" value="{{ old('nama_ortu') }}"></div>
                <div><label for="m-hp">No. HP/WA orang tua <small>(opsional)</small></label><input id="m-hp" type="tel" name="telp_ortu" value="{{ old('telp_ortu') }}" inputmode="tel" placeholder="08xxxxxxxxxx"></div>
            </div>
            <button class="btn btn-pri" style="margin-top:12px" data-busy="Menyimpan…">Simpan &amp; Tambahkan Siswa</button>
        </form>
    </details>
    <details class="card" @if($errors->any() && old('student_id')) open @endif><summary style="font-weight:700">− Siswa Keluar (pindah / berhenti)</summary>
        <form method="post" action="{{ route('tu.mutasi.keluar') }}" style="margin-top:12px" data-confirm="Siswa akan dikeluarkan dari daftar aktif (tidak muncul di absensi). Lanjutkan?">@csrf
            <div class="fields">
                <div><label for="k-kelas">Kelas</label><select id="k-kelas" data-pick-class="#k-siswa"><option value="">— pilih kelas —</option>@foreach($classes as $c)@if(isset($pickStudents[$c->id]))<option value="{{ $c->id }}">{{ $c->name }}</option>@endif @endforeach</select></div>
                <div style="grid-column:span 2"><label for="k-siswa">Nama siswa</label><select id="k-siswa" name="student_id" required><option value="">— pilih siswa —</option>
                    @foreach($classes as $c)@if(isset($pickStudents[$c->id]))<optgroup label="{{ $c->name }}" data-class="{{ $c->id }}">@foreach($pickStudents[$c->id] as $s)<option value="{{ $s->id }}" @selected((int) old('student_id') === $s->id)>{{ $s->nama }} ({{ $s->nis }})</option>@endforeach</optgroup>@endif @endforeach</select></div>
                <div><label for="k-st">Jenis</label>@include('partials.select', ['name' => 'status', 'options' => \App\Services\TuService::ALASAN_KELUAR, 'selected' => old('status', 'pindah')])</div>
                <div><label for="k-tgl">Tanggal keluar</label><input id="k-tgl" type="date" name="tanggal" value="{{ old('tanggal', $today) }}" max="{{ $today }}" required></div>
                <div><label for="k-sek">Sekolah tujuan <small>(bila pindah)</small></label><input id="k-sek" type="text" name="sekolah" value="{{ old('sekolah') }}"></div>
                <div><label for="k-al">Alasan <small>(opsional)</small></label><input id="k-al" type="text" name="alasan" value="{{ old('alasan') }}"></div>
            </div>
            <label class="chk" style="margin-top:8px"><input type="checkbox" name="buat_surat" value="1" checked> Buatkan Surat Keterangan Pindah (nomor otomatis, bila jenis "pindah")</label>
            <button class="btn btn-pri" style="margin-top:8px" data-busy="Menyimpan…">Simpan Mutasi Keluar</button>
        </form>
    </details>
</div>
@endif

<form method="get" action="{{ route('tu.mutasi') }}" class="card filter-card">
    <div class="fields">
        <div><label for="q">Cari</label><input id="q" type="search" name="q" value="{{ $q }}" placeholder="Nama / NIS"></div>
        <div><label for="fj">Jenis</label><select id="fj" name="jenis" data-auto><option value="">Semua</option><option value="masuk" @selected($jenis === 'masuk')>Masuk</option><option value="keluar" @selected($jenis === 'keluar')>Keluar</option></select></div>
        <div style="align-self:end"><button class="btn">Cari</button></div>
    </div>
</form>

<div class="card card-tight">
    @if($list->isEmpty())<div class="empty">Belum ada catatan mutasi.</div>@else
    <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Tanggal</th><th>Siswa</th><th>Jenis</th><th>Kelas</th><th>Sekolah</th><th>Alasan / No. surat</th></tr></thead><tbody>
    @foreach($list as $m)
        <tr><td class="mono" data-label="Tanggal">{{ \App\Support\Dates::human($m->tanggal) }}</td>
            <td class="card-title"><b>{{ $m->student->nama ?? '(dihapus)' }}</b> <small class="mut">{{ $m->student->nis ?? '' }}</small></td>
            <td data-label="Jenis">@if($m->jenis === 'masuk')<span class="badge b-ok">Masuk</span>@else<span class="badge b-warn">Keluar · {{ \App\Services\TuService::ALASAN_KELUAR[$m->status_baru] ?? '' }}</span>@endif</td>
            <td data-label="Kelas">{{ $m->schoolClass->name ?? '-' }}</td>
            <td data-label="{{ $m->jenis === 'masuk' ? 'Sekolah asal' : 'Tujuan' }}">{{ $m->sekolah ?: '-' }}</td>
            <td data-label="Alasan">{{ $m->alasan ?: '-' }}@if($m->nomor_surat)<br><small class="mono mut">{{ $m->nomor_surat }}</small>@endif</td></tr>
    @endforeach
    </tbody></table></div>@endif
    @include('partials.pager', ['p' => $list])
</div>
@endsection
