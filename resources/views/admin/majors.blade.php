@extends('layouts.app', ['title' => 'Jurusan'])
@section('content')
@include('admin._tabs')
@if(! $ready)
    <div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Fitur ini butuh pembaruan database. Buka <a href="{{ route('admin.settings') }}">Pengaturan → Perbarui Database Sekarang</a>.</span></div>
@else
@if($unknown->isNotEmpty())
<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">Ada kode jurusan di data kelas yang belum terdaftar: <b>{{ $unknown->implode(', ') }}</b>.
    <form method="post" action="{{ route('admin.majors.sync') }}" class="inline">@csrf<button class="btn btn-sm">Daftarkan otomatis</button></form></span></div>
@endif

<div class="grid g2" style="align-items:start">
    <div class="card"><h2>Daftar Jurusan</h2>
        @forelse($majors as $m)
            @php($cls = $byCode->get(mb_strtoupper($m->kode), collect()))
            <details style="border-top:1px solid var(--line2);padding:8px 0">
                <summary style="cursor:pointer"><b>{{ $m->kode }}</b>@if($m->nama !== $m->kode) — {{ $m->nama }}@endif <small class="mut">· {{ $cls->count() }} kelas · {{ $cls->sum('aktif_count') }} siswa aktif</small></summary>
                <form method="post" action="{{ route('admin.majors.update', $m) }}" style="margin-top:8px">@csrf @method('PUT')
                    <div class="fields">
                        <div><label>Kode</label><input type="text" name="kode" value="{{ $m->kode }}" maxlength="32" required></div>
                        <div><label>Nama jurusan</label><input type="text" name="nama" value="{{ $m->nama }}" maxlength="191" required></div>
                    </div>
                    <p class="hint" style="margin:6px 0">Mengubah kode ikut mengubah jurusan di semua kelas terkait.</p>
                    <button class="btn btn-sm btn-pri">Simpan</button>
                </form>
                <form method="post" action="{{ route('admin.majors.destroy', $m) }}" class="inline" style="margin-top:8px" data-confirm="Hapus jurusan {{ $m->kode }}? Hanya bisa bila tidak dipakai kelas.">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Hapus jurusan</button></form>
            </details>
        @empty
            <p class="empty">Belum ada jurusan. Tambahkan di bawah.</p>
        @endforelse
        <details open style="border-top:1px solid var(--line);padding-top:10px;margin-top:8px"><summary style="cursor:pointer;font-weight:700">+ Tambah jurusan</summary>
            <form method="post" action="{{ route('admin.majors.store') }}" style="margin-top:8px">@csrf
                <div class="fields">
                    <div><label>Kode</label><input type="text" name="kode" value="{{ old('kode') }}" placeholder="RPL" maxlength="32" required></div>
                    <div><label>Nama jurusan</label><input type="text" name="nama" value="{{ old('nama') }}" placeholder="Rekayasa Perangkat Lunak" maxlength="191" required></div>
                </div>
                <button class="btn btn-pri" style="margin-top:8px">Tambah Jurusan</button>
            </form></details>
    </div>

    <div class="card"><h2>Buat Kelas Sekaligus</h2>
        @if($majors->isEmpty())
            <p class="hint">Tambahkan jurusan dulu.</p>
        @else
        <form method="post" action="{{ route('admin.majors.classes') }}">@csrf
            <div class="fields">
                <div><label>Jurusan</label>@include('partials.select', ['name' => 'jurusan_id', 'options' => $majors->mapWithKeys(fn ($m) => [$m->id => $m->kode.' — '.$m->nama])->all(), 'selected' => ''])</div>
                <div><label>Tingkat</label>@include('partials.select', ['name' => 'tingkat', 'options' => array_combine($tingkat, $tingkat), 'selected' => 'X'])</div>
                <div><label>Jumlah rombel</label><input type="number" name="jumlah" value="1" min="1" max="20" required></div>
            </div>
            <p class="hint" style="margin:6px 0">Contoh: jurusan RPL, tingkat X, 3 rombel → <b>X RPL 1, X RPL 2, X RPL 3</b>. Kelas yang sudah ada dilewati; tahun ajaran & semester mengikuti Pengaturan.</p>
            <button class="btn btn-pri">Buat Kelas</button>
        </form>
        @endif
        <h2 style="margin-top:18px">Kelas per Jurusan</h2>
        @foreach($majors as $m)
            @php($cls = $byCode->get(mb_strtoupper($m->kode), collect()))
            <p style="margin:8px 0 2px"><b>{{ $m->kode }}</b> <small class="mut">{{ $cls->count() }} kelas</small></p>
            <div class="row" style="gap:4px 6px;flex-wrap:wrap">
                @forelse($cls as $c)<span class="badge">{{ $c->name }} · {{ $c->aktif_count }}</span>@empty<small class="mut">belum ada kelas</small>@endforelse
            </div>
        @endforeach
        @if($noMajor->isNotEmpty())
            <p style="margin:12px 0 2px"><b>Tanpa jurusan</b> <small class="mut">{{ $noMajor->count() }} kelas — atur di <a href="{{ route('admin.master') }}">Kelas & Mapel</a></small></p>
            <div class="row" style="gap:4px 6px;flex-wrap:wrap">@foreach($noMajor as $c)<span class="badge">{{ $c->name }} · {{ $c->aktif_count }}</span>@endforeach</div>
        @endif
        <p class="hint" style="margin-top:12px">Angka di samping nama kelas = jumlah siswa aktif.</p>
    </div>
</div>
@endif
@endsection
