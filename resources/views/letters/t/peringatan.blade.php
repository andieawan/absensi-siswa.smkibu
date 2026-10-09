<h1 class="lt">SURAT PERINGATAN</h1>
<p>Surat peringatan ini ditujukan kepada siswa :</p>
@include('letters.t._ident')
<p style="text-align:justify">Adapun surat peringatan ini diterbitkan berdasarkan kesalahan siswa tersebut diatas, berupa:</p>
<p class="uraian">{!! trim((string) ($v['kesalahan'] ?? '')) !== '' ? e($v['kesalahan']) : '<span class="dl"></span><span class="dl"></span>' !!}</p>
<p style="text-align:justify">Kami berharap dengan adanya surat peringatan ini, maka siswa tersebut di atas bisa lebih disiplin dan mematuhi semua peraturan <b><i>SMK Islam Bustanul Ulum ( I B U ) Pakusari dan pelanggaran tata tertib yang berlaku di Lembaga Yayasan Pendidikan Islam Bustanul Ulum Pakusari.</i></b></p>
<p style="text-align:justify">Demikian surat peringatan ini diterbitkan untuk diperhatikan, dan dijadikan pedoman perbaikan diri ke depannya oleh siswa yang bersangkutan serta dibuat dengan sesungguhnya tanpa ada paksaan dari pihak manapun.</p>
@include('letters.t._ttd_ortu')
