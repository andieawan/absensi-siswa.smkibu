@extends('layouts.app', ['title' => 'Cetak Dokumen'])
@section('content')
@include('tu._tabs', ['h' => 'Cetak Dokumen', 'sub' => 'Pilih dokumen, lalu Cetak / Simpan sebagai PDF dari halaman pratinjau.'])
<div class="grid g3" style="align-items:start">
    <form method="get" action="{{ route('tu.print.sheet') }}" class="card" target="_blank"><h2>🗓 Daftar Hadir Kosong</h2><p class="mut" style="font-size:13px">Kolom tanggal satu bulan (hari Minggu dilewati) untuk diisi manual.</p>
        <div class="field"><label for="a-kelas">Kelas</label><select id="a-kelas" name="class" required>@foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->aktif_count }})</option>@endforeach</select></div>
        <div class="field"><label for="a-bulan">Bulan</label><input id="a-bulan" type="month" name="bulan" value="{{ $bulan }}"></div>
        <button class="btn btn-pri">Buka Pratinjau</button></form>
    <form method="get" action="{{ route('tu.print.list') }}" class="card" target="_blank"><h2>📋 Daftar Siswa</h2><p class="mut" style="font-size:13px">Daftar nama per kelas dengan kolom kosong (mis. tanda tangan, nilai, iuran).</p>
        <div class="field"><label for="b-kelas">Kelas</label><select id="b-kelas" name="class" required>@foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->aktif_count }})</option>@endforeach</select></div>
        <div class="fields"><div><label for="b-kol">Kolom kosong</label><input id="b-kol" type="number" name="kolom" min="0" max="8" value="3"></div><div><label for="b-jd">Judul <small>(opsional)</small></label><input id="b-jd" type="text" name="judul" placeholder="DAFTAR SISWA"></div></div>
        <button class="btn btn-pri" style="margin-top:12px">Buka Pratinjau</button></form>
    <form method="get" action="{{ route('tu.print.cards') }}" class="card" target="_blank"><h2>🪪 Kartu Siswa</h2><p class="mut" style="font-size:13px">Kartu identitas sederhana (10 kartu per lembar A4) dengan kotak pas foto.</p>
        <div class="field"><label for="c-kelas">Kelas</label><select id="c-kelas" name="class" required>@foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->aktif_count }})</option>@endforeach</select></div>
        <button class="btn btn-pri">Buka Pratinjau</button></form>
</div>
<p class="hint">Surat keterangan &amp; surat keluar dicetak dari <a href="{{ route('tu.surat', 'keluar') }}">Surat Keluar</a>. Rekap absensi guru dicetak dari <a href="{{ route('tu.staff.recap') }}">Absensi Guru → Rekap</a>.</p>
@endsection
