@php
    $bp = \App\Support\Dates::valid($v['tglk'] ?? '') ? \App\Support\LetterForms::dateParts($v['tglk']) : null;
    $dot = fn ($x, $n = 16) => $x !== null && $x !== '' ? '<b>'.e($x).'</b>' : str_repeat('.', $n);
@endphp
<h1 class="lt">BERITA ACARA PEMANGGILAN<br>ORANG TUA</h1>
<p style="text-align:justify;text-indent:30px">Bahwa pada hari ini {!! $dot($bp['hari'] ?? null) !!} tanggal {!! $dot($bp['tgl'] ?? null, 12) !!} bulan {!! $dot($bp['bulan'] ?? null) !!} Tahun {!! $dot($bp['tahun'] ?? null, 12) !!} pukul {!! $dot($v['mulai'] ?? '', 10) !!} s/d pukul {!! $dot($v['selesai'] ?? '', 10) !!}, telah datang orang tua/wali dari :</p>
<table class="kv ident">
    <tr><td>Nama</td><td>: {{ mb_strtoupper($student->nama) }}</td></tr>
    <tr><td>Kelas</td><td>: {{ $kelas }}</td></tr>
    <tr><td>No. Induk/NISN</td><td>: {{ $student->nis }}</td></tr>
    <tr><td>Alamat</td><td>: {!! $d('alamat', 40) !!}</td></tr>
</table>
<p style="text-align:justify">Untuk membicarakan masalah perkembangan anak tersebut diatas, dengan orang tua/wali siswa demi kelancaran dan perkembangan dalam proses belajar di sekolah</p>
<p>Hasil yang diperoleh adalah :</p>
<p class="uraian">{!! trim((string) ($v['hasil'] ?? '')) !== '' ? e($v['hasil']) : '<span class="dl"></span><span class="dl"></span><span class="dl"></span><span class="dl"></span>' !!}</p>
<p style="text-align:justify;text-indent:20px">Demikian berita acara ini dibuat dengan sebenarnya dalam keadaan sadar tanpa tekanan atau paksaan dari siapapun dan pihak manapun.</p>
<div class="ttd2">
    <div>Guru BK<div class="sp"></div>(............................................)</div>
    <div>{{ $kota }}, {{ $v['tgl'] ?: '.... / .... / '.$now['tahun'] }}<br>Orang tua/wali siswa<div class="sp"></div>(............................................)</div>
</div>
<div class="ttd1c"><b>Mengetahui</b><br>Kepala SMK Islam Bustanul Ulum Pakusari<div class="sp"></div><b><u>{{ $kepsek }}</u></b></div>
