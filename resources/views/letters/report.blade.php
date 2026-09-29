@extends('letters.layout', ['title' => 'Laporan '.$class->name.' — '.$subject->name])
@section('letter')
<div class="kop"><b>LAPORAN EVALUASI PRESENSI &amp; CAPAIAN PEMBELAJARAN SISWA</b><b>{{ mb_strtoupper($settings->school_name) }}</b>
    <span>Tahun Ajaran {{ $settings->tahun_ajaran }} - Semester {{ $settings->semester }}</span></div>
<table>
    <tr><td>Mata Pelajaran</td><td>:</td><td>{{ $subject->name }}</td></tr>
    <tr><td>Kelas</td><td>:</td><td>{{ $class->name }}</td></tr>
    <tr><td>Waktu Cetak</td><td>:</td><td>{{ \App\Support\Dates::long() }}</td></tr>
</table>
<h3 style="margin-top:14px">I. RINGKASAN KEHADIRAN KELAS</h3>
<ul><li>Rata-rata Kehadiran Kelas: <b>{{ $pct }}%</b></li><li>Total Siswa Aktif: {{ $rows->count() }} orang</li><li>Jumlah Sesi Pertemuan: {{ $sessions }} sesi</li></ul>
<h3>II. DAFTAR CAPAIAN SISWA</h3>
<table border="1" cellpadding="4" style="width:100%;font-size:11pt">
    <tr><th>No</th><th>NIS</th><th>Nama Siswa</th><th>Kehadiran</th><th>Nilai Rata-rata</th></tr>
    @foreach($rows as $i => $r)
        <tr><td>{{ $i + 1 }}</td><td>{{ $r['s']->nis }}</td><td>{{ $r['s']->nama }}</td><td style="text-align:center">{{ $r['rate'] }}%</td><td style="text-align:center">{{ $r['avg'] }}</td></tr>
    @endforeach
</table>
<h3 style="margin-top:14px">III. CATATAN &amp; REKOMENDASI PEMBELAJARAN</h3>
<p>Peserta didik dengan tingkat kehadiran di bawah 85% atau capaian nilai belum tuntas dijadwalkan mengikuti program remedial serta sesi pendampingan konseling.</p>
<div class="ttd"><div></div><div>Guru Pengampu Mata Pelajaran,<div class="sp"></div>(......................................)<br>NIP. ....................</div></div>
@endsection
