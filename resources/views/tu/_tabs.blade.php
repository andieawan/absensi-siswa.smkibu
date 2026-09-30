@php
    $h = $h ?? (request()->routeIs('tu.students') ? 'Data Siswa' : 'Tata Usaha');
    $sub = $sub ?? (request()->routeIs('tu.students') ? 'Tambah, ubah, dan impor data siswa (termasuk kontak orang tua).' : '');
    $arahNow = request()->route('arah');
    $tuTabs = [['tu.home', 'Ringkasan', [], request()->routeIs('tu.home')]];
    if (auth()->user()->can('tu')) $tuTabs[] = ['tu.students', 'Data Siswa', [], request()->routeIs('tu.students*')];
    $tuTabs[] = ['tu.mutasi', 'Mutasi', [], request()->routeIs('tu.mutasi*')];
    $tuTabs[] = ['tu.surat', 'Surat Keluar', ['keluar'], request()->routeIs('tu.surat') && $arahNow === 'keluar'];
    $tuTabs[] = ['tu.surat', 'Surat Masuk', ['masuk'], request()->routeIs('tu.surat') && $arahNow === 'masuk'];
    $tuTabs[] = ['tu.staff', 'Absensi Guru', [], request()->routeIs('tu.staff*')];
    $tuTabs[] = ['tu.print', 'Cetak', [], request()->routeIs('tu.print')];
@endphp
<div class="head"><div><h1>{{ $h }}</h1>@if($sub)<small>{{ $sub }}</small>@endif</div></div>
<div class="seg seg-scroll" role="navigation" aria-label="Bagian Tata Usaha" style="margin-bottom:14px">
    @foreach($tuTabs as [$r, $l, $args, $on])
        <a href="{{ route($r, $args) }}" @class(['on' => $on])>{{ $l }}</a>
    @endforeach
</div>
@if(session('print_url'))
<div class="alert alert-info"><span class="alert-ic" aria-hidden="true">🖨</span><span class="alert-txt">Surat siap dicetak: <a target="_blank" href="{{ session('print_url') }}"><b>Buka &amp; cetak surat</b></a></span></div>
@endif
