@extends('letters.layout', ['title' => 'Surat Panggilan — '.$student->nama])
@php($v = fn ($x, $d = '........................') => trim((string) $x) !== '' ? $x : $d)
@section('toolbar')
    <label>Tempat surat<input type="text" name="tempat" value="{{ $tempat ?? '' }}" placeholder="mis. Pakusari"></label>
    <label>Hari/Tanggal<input type="text" name="tgl" value="{{ $tgl ?? '' }}" placeholder="Senin, 6 Oktober 2026"></label>
    <label>Jam<input type="text" name="jam" value="{{ $jam ?? '' }}" placeholder="09.00"></label>
    <label>Ruang<input type="text" name="ruang" value="{{ $ruang ?? '' }}" placeholder="BK"></label>
    <label>Alasan<input type="text" name="alasan" value="{{ $alasan ?? '' }}" placeholder="ketidakhadiran berulang"></label>
@endsection
@section('letter')
@include('letters._kop', ['sub' => 'LAYANAN BIMBINGAN DAN KONSELING (BK)'])
<p style="text-align:center"><b><u>SURAT PANGGILAN ORANG TUA / WALI SISWA</u></b><br>Nomor: 421.7 / BK-PANGGILAN / {{ now(\App\Support\Dates::tz())->year }}</p>
<p>Kepada Yth.<br>Bapak / Ibu Orang Tua / Wali dari:</p>
<table>
    <tr><td>Nama Siswa</td><td>:</td><td>{{ $student->nama }}</td></tr>
    <tr><td>NIS</td><td>:</td><td>{{ $student->nis }}</td></tr>
    <tr><td>Kelas</td><td>:</td><td>{{ $student->schoolClass->name ?? '-' }}</td></tr>
</table>
<p>Dengan hormat,<br>Sehubungan dengan adanya hal penting terkait evaluasi kedisiplinan dan capaian belajar siswa ({{ $v($alasan ?? '', 'ketidakhadiran') }}), maka melalui surat ini kami mengharapkan kehadiran Bapak/Ibu pada:</p>
<table style="margin-left:24px">
    <tr><td>Hari / Tanggal</td><td>:</td><td>{{ $v($tgl ?? '') }}</td></tr>
    <tr><td>Waktu</td><td>:</td><td>Pukul {{ $v($jam ?? '', '....') }} WIB</td></tr>
    <tr><td>Tempat</td><td>:</td><td>Ruang {{ $v($ruang ?? '', '..........') }}</td></tr>
    <tr><td>Bertemu Dengan</td><td>:</td><td>Guru Bimbingan Konseling &amp; Wali Kelas</td></tr>
</table>
<p>Mengingat sangat pentingnya koordinasi ini demi masa depan belajar putra/putri Bapak/Ibu, kami sangat mengharapkan kehadiran Bapak/Ibu tepat pada waktu yang telah ditentukan.</p>
<p>Atas perhatian dan kerja samanya, kami ucapkan terima kasih.</p>
@include('letters._ttd', ['bkLabel' => 'Guru Bimbingan Konseling', 'place' => $tempat ?? ''])
@endsection
