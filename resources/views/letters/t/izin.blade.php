@php
    $ip = \App\Support\Dates::valid($v['tglk'] ?? '') ? \App\Support\LetterForms::dateParts($v['tglk']) : $now;
    $n = max(1, min(4, (int) ($v['salinan'] ?? 2)));
@endphp
@for($i = 0; $i < $n; $i++)
<section class="izin">
    @include('letters._kop')
    <h1 class="lt" style="font-size:12pt;margin:14px 0 10px">SURAT IZIN MENINGGALKAN SEKOLAH</h1>
    <p>Hari &nbsp;&nbsp;<b>{{ mb_strtoupper($ip['hari']) }}</b> &nbsp; Tanggal &nbsp;&nbsp;<b>{{ $ip['tgl'] }}</b> &nbsp; Bulan &nbsp;&nbsp;<b>{{ mb_strtoupper($ip['bulan']) }}</b> &nbsp; Tahun &nbsp;<b>{{ $ip['tahun'] }}</b></p>
    <table class="kv">
        <tr><td>Nama Siswa</td><td>: <b>{{ mb_strtoupper($student->nama) }}</b></td></tr>
        <tr><td>Kelas</td><td>: <b>{{ mb_strtoupper($kelas) }}</b></td></tr>
        <tr><td>Alasan</td><td>: <b>{!! trim((string) ($v['alasan'] ?? '')) !== '' ? e(mb_strtoupper($v['alasan'])) : str_repeat('.', 40) !!}</b></td></tr>
    </table>
    <p>Dari alasan tersebut diatas diperkenankan untuk meninggalkan sekolah.</p>
    <p>Demikian harap dimaklumi!</p>
    <p>{{ $kota }}, <b>{{ mb_strtoupper($ip['tgl'].' '.$ip['bulan'].' '.$ip['tahun']) }}</b></p>
    <div class="ttd2 sm"><div>Guru yang Menangani<div class="sp"></div>( {{ trim((string) ($v['guru'] ?? '')) !== '' ? $v['guru'] : str_repeat('.', 28) }} )</div><div>Ttd. Siswa<div class="sp"></div>( <b>{{ mb_strtoupper($student->nama) }}</b> )</div></div>
</section>
@endfor
