@extends('letters.layout', ['title' => 'Surat Peringatan — '.$student->nama])
@section('toolbar')<label>Tempat surat<input type="text" name="tempat" value="{{ $place }}" placeholder="mis. Pakusari"></label>@endsection
@section('letter')
@include('letters._kop')
<table>
    <tr><td>Nomor</td><td>:</td><td>421.5 / SP-BK / {{ now(\App\Support\Dates::tz())->year }}</td></tr>
    <tr><td>Lampiran</td><td>:</td><td>1 (Satu) Berkas Riwayat Kehadiran</td></tr>
    <tr><td>Perihal</td><td>:</td><td><b>Peringatan Ketidakhadiran &amp; Bimbingan Konseling Siswa</b></td></tr>
</table>
<p>Kepada Yth.<br>Bapak/Ibu Orang Tua / Wali dari Ananda:</p>
<table>
    <tr><td>Nama Siswa</td><td>:</td><td>{{ $student->nama }}</td></tr>
    <tr><td>Nomor Induk (NIS)</td><td>:</td><td>{{ $student->nis }}</td></tr>
    <tr><td>Kelas / Program</td><td>:</td><td>{{ $student->schoolClass->name ?? '-' }}</td></tr>
    <tr><td>Tahun Pelajaran</td><td>:</td><td>{{ $settings->tahun_ajaran }} ({{ $settings->semester }})</td></tr>
</table>
<p>Dengan hormat,<br>Berdasarkan rekapitulasi data presensi elektronik pada sistem informasi sekolah, dengan ini kami memberitahukan bahwa putra/putri Bapak/Ibu tercatat mengalami ketidakhadiran dengan rincian sebagai berikut:</p>
<table style="margin-left:24px">
    <tr><td>1. Alpa (Tanpa Keterangan)</td><td>:</td><td>{{ $alpa->count() }} kali pertemuan</td></tr>
    <tr><td>2. Izin Resmi</td><td>:</td><td>{{ $izin }} kali pertemuan</td></tr>
    <tr><td>3. Sakit</td><td>:</td><td>{{ $sakit }} kali pertemuan</td></tr>
    <tr><td><b>Total Ketidakhadiran</b></td><td>:</td><td><b>{{ $alpa->count() + $izin + $sakit }} kali pertemuan</b></td></tr>
</table>
@if($alpa->isNotEmpty())
    <p>Rincian Tanggal Alpa:</p>
    <ul>@foreach($alpa as $a)<li>Tanggal: {{ $a->tanggal }} | Catatan: {{ $a->notes ?? 'Tanpa keterangan resmi' }}</li>@endforeach</ul>
@endif
<p>Mengingat pentingnya pemenuhan syarat minimal kehadiran (85%) untuk evaluasi kenaikan kelas dan kelulusan, kami mengharapkan perhatian dan kerja sama Bapak/Ibu dalam mengawasi serta membimbing Ananda.</p>
<p>Demikian surat pemberitahuan ini kami sampaikan, atas perhatian dan kerja sama yang baik kami ucapkan terima kasih.</p>
@include('letters._ttd', ['bkLabel' => 'Guru Bimbingan Konseling (BK)', 'place' => $place])
@endsection
