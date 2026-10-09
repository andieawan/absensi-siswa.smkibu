<table class="kv ident">
    <tr><td>Nama</td><td>: {{ mb_strtoupper($student->nama) }}</td></tr>
    <tr><td>Tempat, Tgl Lahir</td><td>: {!! $d('ttl', 25) !!}</td></tr>
    <tr><td>Kelas</td><td>: {{ $kelas }}</td></tr>
    <tr><td>Jenis Kelamin</td><td>: {{ $jk }}</td></tr>
    <tr><td>Alamat</td><td>: {!! $d('alamat', 40) !!}</td></tr>
    @isset($v['pelanggaran'])<tr><td>Jenis Pelanggaran</td><td>: {!! $d('pelanggaran', 30) !!}</td></tr>@endisset
    <tr><td>No.Hp. Ortu</td><td>: {!! $d('hp', 20) !!}</td></tr>
    @isset($v['alasan'])<tr><td>Alasan</td><td>: {!! $d('alasan', 40) !!}</td></tr>@endisset
</table>
