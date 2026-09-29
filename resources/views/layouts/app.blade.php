<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Absensi' }} · {{ $settings->school_name }}</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ @filemtime(public_path('assets/app.css')) }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    <meta name="theme-color" content="#4f46e5">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Absensi">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">
    <meta name="sw-url" content="{{ asset('sw.js') }}">
    @stack('head')
</head>
<body @class(['bare' => $bare ?? false])>
@auth
    @unless($bare ?? false)
    @php($me = auth()->user())
    <header class="nav"><div class="nav-in">
        <a class="brand" href="{{ route('dashboard') }}"><span class="logo">A</span><span><b>Absensi Siswa</b><small>{{ $settings->school_name }}</small></span></a>
        <nav class="tabs" aria-label="Menu utama">
            @php($tabs = ['dashboard' => 'Dashboard', 'attendance' => 'Absensi', 'grades' => 'Nilai', 'students' => 'Riwayat Siswa'])
            @can('bk') @php($tabs['bk'] = 'Integrasi BK') @endcan
            @can('admin') @php($tabs['admin.teachers'] = 'Admin Panel') @endcan
            @foreach($tabs as $r => $label)
                @php($on = request()->routeIs($r === 'admin.teachers' ? 'admin.*' : $r.'*'))
                <a href="{{ route($r) }}" @if($on) class="on" aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="who">
            <span class="who-name">{{ $me->nama }}<small>{{ $me->roleLabel() }}</small></span>
            <button type="button" class="btn btn-sm btn-pri hide" id="pwa-install" title="Pasang aplikasi di perangkat ini">Pasang Aplikasi</button>
            <a class="btn btn-sm" href="{{ route('password') }}">Password</a>
            <a class="btn btn-sm" href="{{ route('switch') }}">Ganti Akun</a>
            <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button class="btn btn-sm btn-dark">Keluar</button></form>
        </div>
    </div></header>
    @endunless
@endauth
<main @class(['wrap', 'wrap-bare' => $bare ?? false]) @isset($width) style="max-width:{{ $width }}" @endisset>
    @include('partials.flash')
    @yield('content')
</main>
<script src="{{ asset('assets/app.js') }}?v={{ @filemtime(public_path('assets/app.js')) }}"></script>
</body>
</html>
