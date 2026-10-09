@include('letters._kop')
<table class="kv">
    <tr><td>Nomor</td><td>: {{ $cfg['nomor_awal'] }}/{!! trim($v['no'] ?? '') !== '' ? e(str_pad($v['no'], 3, '0', STR_PAD_LEFT)) : '.....' !!}/{{ $cfg['nomor_akhir'] }}/{{ $now['tahun'] }}</td></tr>
    <tr><td>Lampiran</td><td>: -</td></tr>
    <tr><td>Perihal</td><td>: Undangan Wali Murid</td></tr>
</table>
<p style="margin:18px 0 0 28px"><b>Kepada<br>Yth. Bapak/Ibu Wali Murid<br>di<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Tempat</b></p>
<p style="margin-top:22px">Assalamualaikum wr wb</p>
<p style="text-indent:36px;text-align:justify">Dalam rangka kelancaran proses belajar mengajar dan kesinambungan anak didik antar sekolah dan wali murid, maka kami mengharap kehadiran Bapak/Ibu Wali Murid atas nama :</p>
<table class="kv" style="margin-left:14px">
    <tr><td>Nama</td><td>: {{ mb_strtoupper($student->nama) }}</td></tr>
    <tr><td>Kelas</td><td>: {{ mb_strtoupper($kelas) }}</td></tr>
    <tr><td>Jam</td><td>: {!! $d('jam', 12) !!}</td></tr>
    <tr><td>Hari, Tanggal</td><td>: {!! mb_strtoupper(trim((string) ($v['hari'] ?? '')) !== '' ? e($v['hari']) : '..................') !!}</td></tr>
    <tr><td>Menemui</td><td>: {!! $d('menemui', 20) !!}</td></tr>
    <tr><td>Tempat</td><td>: {!! $d('tempat_temu', 20) !!}</td></tr>
</table>
<p style="text-indent:36px;text-align:justify;margin-top:14px">Mengingat pentingnya surat panggilan tersebut, kehadiran Bapak/Ibu sangat kami harapkan. Demikian surat panggilan ini, atas perhatiannya kami ucapkan Terima Kasih.</p>
<p style="margin-top:22px">Wassalamualaikum wr wb</p>
<div class="ttd1">
    <div>{{ $kota }}, {{ $v['tgl'] ?: $now['long'] }}<br>Kepala SMK Islam Bustanul Ulum Pakusari<div class="sp"></div><b><u>{{ $kepsek }}</u></b><br><b>Nuptk. {{ $cfg['kepsek_nuptk'] }}</b></div>
</div>
@if(trim((string) ($v['nb'] ?? '')) !== '')<p style="margin-top:26px"><b>NB: {{ $v['nb'] }}</b></p>@endif
