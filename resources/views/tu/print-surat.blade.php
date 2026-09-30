@extends('letters.layout', ['title' => $surat->perihal])
@php
    $s = $surat->student; $x = fn ($k, $d = '........................') => trim($surat->x($k)) !== '' ? $surat->x($k) : $d;
    $tgl = fn ($ymd) => \App\Support\Dates::long($ymd);
    $tahun = \App\Support\TahunAjaran::label();
@endphp
@section('toolbar')
    <label>Tempat surat<input type="text" name="tempat" value="{{ $tempat ?? '' }}" placeholder="mis. Pakusari"></label>
    <label>NIP Kepala Sekolah<input type="text" name="nip" value="{{ $nip ?? '' }}" placeholder="opsional"></label>
@endsection
@section('letter')
@include('letters._kop', ['sub' => 'Alamat sekolah: ........................................'])
@php($judul = $surat->jenis === 'biasa' ? $surat->perihal : mb_strtoupper(\App\Services\TuService::KETERANGAN[$surat->jenis]))
<p style="text-align:center;margin-top:14px"><b><u>{{ mb_strtoupper($judul) }}</u></b><br>Nomor: {{ $surat->nomor ?: '........................' }}</p>
@if($surat->jenis === 'biasa')
    @if($surat->pihak)<p>Kepada Yth.<br>{{ $surat->pihak }}</p>@endif
    <p style="white-space:pre-line">{{ $surat->isi ?: '........................................' }}</p>
@else
    <p>Yang bertanda tangan di bawah ini, Kepala {{ $settings->school_name }}, menerangkan bahwa:</p>
    <table style="margin-left:24px">
        <tr><td>Nama</td><td>:</td><td><b>{{ $s->nama ?? '-' }}</b></td></tr>
        <tr><td>NIS</td><td>:</td><td>{{ $s->nis ?? '-' }}</td></tr>
        <tr><td>Jenis kelamin</td><td>:</td><td>{{ ($s->jk ?? '') === 'P' ? 'Perempuan' : 'Laki-laki' }}</td></tr>
        <tr><td>Kelas</td><td>:</td><td>{{ $s->schoolClass->name ?? '-' }}</td></tr>
    </table>
    @if($surat->jenis === 'aktif')
        <p>adalah benar siswa aktif pada {{ $settings->school_name }} pada Tahun Ajaran {{ $tahun }}.</p>
        <p>Surat keterangan ini dibuat untuk keperluan {{ $x('keperluan') }}.</p>
    @elseif($surat->jenis === 'pindah')
        <p>adalah siswa {{ $settings->school_name }} yang mengajukan pindah ke <b>{{ $x('sekolah_tujuan') }}</b> terhitung mulai tanggal {{ $tgl($surat->tanggal) }}@if($surat->x('alasan')), dengan alasan {{ $surat->x('alasan') }}@endif.</p>
        <p>Demikian surat keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya.</p>
    @else
        @php($mulai = $surat->x('mulai')) @php($sampai = $surat->x('sampai') ?: $mulai)
        <p>diberikan {{ $surat->jenis === 'dispensasi' ? 'dispensasi' : 'izin' }} untuk tidak mengikuti kegiatan belajar mengajar pada {{ $mulai === $sampai ? 'tanggal '.$tgl($mulai) : 'tanggal '.$tgl($mulai).' s.d. '.$tgl($sampai) }}, dengan alasan {{ $x('alasan') }}.</p>
        <p>Demikian surat ini dibuat untuk dapat dipergunakan sebagaimana mestinya.</p>
    @endif
@endif
<p style="text-align:right;margin-top:28px">{{ ($tempat ?? '') !== '' ? $tempat : '........................' }}, {{ $tgl($surat->tanggal) }}</p>
<div class="ttd" style="justify-content:flex-end"><div>Kepala Sekolah,<div class="sp"></div><b><u>{{ $settings->kepsek_nama ?: '(........................)' }}</u></b><br>NIP. {{ ($nip ?? '') !== '' ? $nip : '....................' }}</div></div>
@endsection
