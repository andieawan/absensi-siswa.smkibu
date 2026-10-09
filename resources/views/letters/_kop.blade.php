@php($c = $cfg ?? config('absensi.surat'))
<header class="kop2">
    <img src="{{ asset('assets/logo-ibu.png') }}" alt="" width="84" height="84">
    <div>
        <div class="k1">{{ $c['yayasan'] }}</div>
        <div class="k1">{{ $c['yayasan2'] }}</div>
        <div class="k2">{{ $c['nama'] }}</div>
        <div class="k3">NSS : {{ $c['nss'] }} &nbsp;&nbsp;&nbsp; NPSN : {{ $c['npsn'] }}</div>
        <div class="k4">{{ $c['bidang'] }}</div>
        <div class="k4">{{ $c['alamat'] }}</div>
    </div>
</header>
