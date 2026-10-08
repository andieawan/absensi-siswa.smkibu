@extends('layouts.app', ['title' => 'Impor Data'])
@section('content')
@include('admin._tabs')
@php $res = session('import_result'); @endphp
@if($res)
    <div class="card" style="border-left:4px solid {{ count($res['skip']) ? 'var(--warn, #d97706)' : 'var(--ok, #16a34a)' }}">
        <h2 style="margin-top:0">{{ $res['dry'] ? 'Hasil pengecekan' : 'Hasil impor' }}: {{ $res['title'] }}</h2>
        <p><b>{{ $res['ok'] }}</b> baris {{ $res['dry'] ? 'siap diimpor (belum disimpan — hilangkan centang "Cek dulu" lalu unggah lagi untuk menyimpan)' : 'berhasil ditambahkan' }}@if(count($res['skip'])), <b>{{ count($res['skip']) }}</b> baris dilewati{{ '' }}@endif.</p>
        @if(count($res['skip']))
            <details open><summary style="cursor:pointer;font-weight:600">Baris yang dilewati</summary>
                <ul style="margin:8px 0 0;padding-left:18px;font-size:13px">@foreach(array_slice($res['skip'], 0, 100) as $s)<li>{{ $s }}</li>@endforeach @if(count($res['skip']) > 100)<li>… dan {{ count($res['skip']) - 100 }} lainnya</li>@endif</ul>
            </details>
        @endif
        @if(! empty($res['passwords']) && ! $res['dry'])
            <div class="alert alert-warn" style="margin-top:12px"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt"><b>Catat password ini sekarang</b> — hanya ditampilkan sekali. Bagikan ke tiap pengguna dan minta mereka menggantinya di menu Password.</span></div>
            <div class="scroll"><table class="tbl"><thead><tr><th>Username</th><th>Password sementara</th></tr></thead><tbody>
                @foreach($res['passwords'] as $u => $p)<tr><td class="mono">{{ $u }}</td><td class="mono"><b>{{ $p }}</b></td></tr>@endforeach
            </tbody></table></div>
            <button type="button" class="btn btn-sm" style="margin-top:8px" onclick="window.print()">Cetak daftar</button>
        @endif
    </div>
@endif
<div class="card">
    <h2 style="margin-top:0">Impor data massal</h2>
    <p class="mut" style="font-size:13px;margin:0">Urutan yang disarankan: <b>① Kelas → ② Mapel → ③ Guru &amp; Staf → ④ Siswa → ⑤ Pasangan Mapel → ⑥ Riwayat Absensi → ⑦ Riwayat Nilai</b>. Unduh template, isi di Excel/Google Sheets, lalu unggah (.xlsx atau .csv). Centang <b>Cek dulu</b> untuk melihat hasilnya tanpa menyimpan.</p>
    @unless($xlsx)<div class="alert alert-warn" style="margin-top:10px">Ekstensi PHP zip belum aktif di server — template .xlsx tidak bisa dibuat. Gunakan berkas .csv.</div>@endunless
</div>
<div class="grid g2" style="align-items:start">
    @foreach(['kelas', 'mapel', 'guru', 'siswa', 'pasangan', 'absensi', 'nilai'] as $i => $k)
        @php $t = $types[$k]; @endphp
        <div class="card">
            <h2 style="margin-top:0">{{ ['①','②','③','④','⑤','⑥','⑦'][$i] }} {{ $t['icon'] }} {{ $t['title'] }}</h2>
            <p class="mut" style="font-size:12.5px;margin:0 0 6px">Kolom: <b>{{ implode(', ', $t['cols']) }}</b></p>
            <ul style="margin:0 0 10px;padding-left:18px;font-size:12.5px" class="mut">@foreach($t['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
            <a class="btn btn-sm" href="{{ route('admin.import.template', $k) }}">⬇ Unduh template</a>
            <form method="post" enctype="multipart/form-data" action="{{ route('admin.import.run', $k) }}" style="margin-top:12px">@csrf
                <input type="file" name="file" accept=".xlsx,.csv" required aria-label="Berkas {{ $t['title'] }}">
                <label class="chk" style="display:flex;gap:6px;align-items:center;margin-top:8px;font-size:13px"><input type="checkbox" name="dry" value="1" checked> Cek dulu (jangan simpan)</label>
                <button class="btn btn-pri" style="margin-top:8px">Unggah &amp; Proses</button>
            </form>
        </div>
    @endforeach
    <div class="card">
        <h2 style="margin-top:0">⑧ 📝 Absensi Hardcopy</h2>
        <p class="mut" style="font-size:12.5px;margin:0 0 10px">Untuk memasukkan absensi harian dari kertas: unduh template per kelas &amp; tanggal, isi status H/I/S/A, unggah, cek pratinjau, lalu simpan. Dipakai sebagai cadangan bila absensi tidak sempat dilakukan langsung di aplikasi.</p>
        <a class="btn btn-pri" href="{{ route('admin.hardcopy') }}">Buka Upload Hardcopy →</a>
    </div>
</div>
@endsection
