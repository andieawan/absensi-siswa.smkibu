<h1 class="lt">SURAT PERNYATAAN</h1>
<p>Yang bertanda tangan di bawah ini :</p>
@include('letters.t._ident')
<p class="uraian">{!! trim((string) ($v['uraian'] ?? '')) !== '' ? e($v['uraian']) : '<span class="dl"></span><span class="dl"></span>' !!}</p>
<p style="text-align:justify">Menyatakan &nbsp;<b><u>SIAP DI BERHENTIKAN</u></b> dari <b><i>SMK Islam Bustanul Ulum ( I B U ) Pakusari jika saya mengulangi kesalahan dan pelanggaran tata tertib yang berlaku di Lembaga Yayasan Pendidikan Islam Bustanul Ulum Pakusari.</i></b></p>
<p>Demikian surat ini saya buat dengan sesungguhnya tanpa ada paksaan dari pihak manapun.</p>
@include('letters.t._ttd_ortu')
