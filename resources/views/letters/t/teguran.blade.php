<h1 class="lt">SURAT TEGURAN TERTULIS</h1>
<table class="kv">
    <tr><td>Hal</td><td>: Surat Teguran Tertulis</td></tr>
    <tr><td>Lampiran</td><td>: -</td></tr>
</table>
<p style="margin-top:12px">Kepada Yth.<br>Bapak/Ibu Orang Tua/Wali<br>Dari {!! $d('dari', 24) !!}<br>di Tempat</p>
<p>Dengan hormat,<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Dengan ini kami sampaikan kepada bapak/ibu/wali dari :</p>
<table class="kv" style="margin-left:36px">
    <tr><td>Nama</td><td>: {{ mb_strtoupper($student->nama) }}</td></tr>
    <tr><td>Kelas</td><td>: {{ $kelas }}</td></tr>
</table>
<p style="text-align:justify">Bahwa siswa tersebut telah melakukan pelanggaran tata tertib berupa : {!! $d('pelanggaran', 60) !!}</p>
<p style="text-align:justify">Oleh karena itu Kami memberikan surat teguran tertulis, dengan surat ini, diharapkan agar kiranya bapak/ibu/wali lebih mengawasi kegiatan siswa/i baik dari segi sikap individu, sosial dan spiritual. Agar dikemudian hari siswa tersebut tidak mengulangi kesalahan lainnya, sehingga tercipta perilaku siswa yang lebih baik lagi kedepan.</p>
<p style="text-align:justify">Demikian surat teguran tertulis ini diterbitkan untuk diperhatikan, dan dijadikan pedoman perbaikan diri ke depannya oleh siswa/i, sekian atas kerja samanya kami sampaikan terimakasih.</p>
@include('letters.t._ttd_ortu')
