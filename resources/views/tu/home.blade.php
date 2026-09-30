@extends('layouts.app', ['title' => 'Tata Usaha'])
@section('content')
@php($w = auth()->user()->can('tu'))
@include('tu._tabs', ['h' => 'Tata Usaha', 'sub' => $w ? 'Administrasi siswa, surat, dan kehadiran guru & staf.' : 'Ringkasan administrasi (baca saja).'])

<div class="kpis kpis-4">
    <div class="kpi"><small>Siswa aktif</small><div class="n">{{ $siswaAktif }}</div></div>
    <div class="kpi"><small>Mutasi bulan ini</small><div class="n">{{ $mutasiMasuk + $mutasiKeluar }}</div><small>{{ $mutasiMasuk }} masuk · {{ $mutasiKeluar }} keluar</small></div>
    <div class="kpi"><small>Surat masuk belum selesai</small><div class="n {{ $masukBelum ? 'warn' : 'ok' }}">{{ $masukBelum }}</div></div>
    <div class="kpi"><small>Absen guru hari ini</small><div class="n {{ $staffHariIni ? 'ok' : 'bad' }}">{{ $staffHariIni ? 'Sudah' : 'Belum' }}</div><small>{{ $staff }} guru &amp; staf</small></div>
</div>

<div class="quick">
    @if($w)<a class="quick-item primary" href="{{ route('tu.staff') }}"><span class="bk-ic" aria-hidden="true">🗓</span><span><b>Absensi Guru &amp; Staf</b><small>Isi kehadiran hari ini</small></span></a>@endif
    @if($w)<a class="quick-item" href="{{ route('tu.students') }}"><span class="bk-ic" aria-hidden="true">🎓</span><span><b>Data Siswa</b><small>Tambah, ubah, impor</small></span></a>@endif
    <a class="quick-item" href="{{ route('tu.mutasi') }}"><span class="bk-ic" aria-hidden="true">🔁</span><span><b>Mutasi Siswa</b><small>Masuk &amp; keluar</small></span></a>
    <a class="quick-item" href="{{ route('tu.surat', 'keluar') }}"><span class="bk-ic" aria-hidden="true">📤</span><span><b>Surat Keluar</b><small>{{ $keluarBulan }} surat bulan ini</small></span></a>
    <a class="quick-item" href="{{ route('tu.surat', 'masuk') }}"><span class="bk-ic" aria-hidden="true">📥</span><span><b>Surat Masuk</b><small>Register &amp; disposisi</small></span></a>
    <a class="quick-item" href="{{ route('tu.print') }}"><span class="bk-ic" aria-hidden="true">🖨</span><span><b>Cetak Dokumen</b><small>Daftar hadir, kartu siswa</small></span></a>
</div>

<div class="card card-tight">
    <div style="padding:14px 16px;border-bottom:1px solid var(--line2)"><h2>Surat Terbaru</h2></div>
    @forelse($latest as $s)
        <a class="stu" style="color:inherit" href="{{ route('tu.surat', $s->arah) }}"><span aria-hidden="true">{{ $s->arah === 'masuk' ? '📥' : '📤' }}</span>
            <span class="nm"><b>{{ $s->perihal }}</b><small>{{ $s->nomor ?: 'Agenda '.$s->urut.'/'.$s->tahun }} · {{ \App\Support\Dates::human($s->tanggal) }}</small></span></a>
    @empty
        <div class="empty">Belum ada surat tercatat.</div>
    @endforelse
</div>
@endsection
