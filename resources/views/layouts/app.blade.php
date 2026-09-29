<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Absensi' }} · {{ $settings->school_name }}</title>
    <script>document.documentElement.classList.add('js')</script>
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
<body @class(['bare' => $bare ?? false, 'has-bottom-nav' => auth()->check() && ! ($bare ?? false)])>
<a class="skip" href="#isi">Lompat ke isi halaman</a>
{{-- Ikon (SVG sederhana, tanpa library luar) --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="m8.5 12.5 2.5 2.5 4.5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
    <symbol id="i-star" viewBox="0 0 24 24"><path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M2.5 20c.6-3.4 3.2-5.5 6.5-5.5s5.9 2.1 6.5 5.5M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.8c1.9.7 3.2 2.5 3.5 5.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
    <symbol id="i-heart" viewBox="0 0 24 24"><path d="M12 20s-7.5-4.4-7.5-10A4.3 4.3 0 0 1 12 7.6 4.3 4.3 0 0 1 19.5 10c0 5.6-7.5 10-7.5 10z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></symbol>
    <symbol id="i-gear" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
</svg>
@auth
    @unless($bare ?? false)
    @php
        $me = auth()->user();
        $tabs = [
            ['dashboard', 'Dashboard', 'Beranda', 'i-home', 'dashboard'],
            ['attendance', 'Absensi', 'Absensi', 'i-check', 'attendance*'],
            ['grades', 'Nilai', 'Nilai', 'i-star', 'grades*'],
            ['students', 'Siswa', 'Siswa', 'i-users', 'students*'],
        ];
        $extra = [];
        if ($me->can('bk')) $extra[] = ['bk', 'BK', 'BK', 'i-heart', 'bk*'];
        if ($me->can('admin')) $extra[] = ['admin.teachers', 'Admin', 'Admin', 'i-gear', 'admin.*'];
        $initial = mb_strtoupper(mb_substr(trim(preg_replace('/^(Dra?\.|Drs\.|Ir\.|H\.|Hj\.)\s*/i', '', $me->nama)), 0, 1));
    @endphp
    <header class="nav"><div class="nav-in">
        <a class="brand" href="{{ route('dashboard') }}"><img class="logo-img" src="{{ asset('icons/icon-192.png') }}" alt="" width="36" height="36"><span><b>Absensi Siswa</b><small>{{ $settings->school_name }}</small></span></a>
        <nav class="tabs" aria-label="Menu utama">
            @foreach(array_merge($tabs, $extra) as [$r, $label, , $icon, $pat])
                <a href="{{ route($r) }}" @if(request()->routeIs($pat)) class="on" aria-current="page" @endif><svg class="ic"><use href="#{{ $icon }}"/></svg>{{ $label }}</a>
            @endforeach
        </nav>
        <details class="acct" data-close-outside>
            <summary aria-label="Menu akun {{ $me->nama }}"><span class="avatar" aria-hidden="true">{{ $initial }}</span><span class="acct-name">{{ $me->nama }}<small>{{ $me->roleLabel() }}</small></span><span class="caret" aria-hidden="true">▾</span></summary>
            <div class="acct-menu" role="menu">
                <div class="acct-head"><b>{{ $me->nama }}</b><small>{{ '@'.$me->username }} · {{ $me->roleLabel() }}</small></div>
                @foreach($extra as [$r, $label, , $icon])
                    <a class="only-mobile" role="menuitem" href="{{ route($r) }}"><svg class="ic"><use href="#{{ $icon }}"/></svg>{{ $label }}</a>
                @endforeach
                <button type="button" class="menu-btn hide" id="pwa-install" role="menuitem">📲 Pasang Aplikasi</button>
                <a role="menuitem" href="{{ route('password') }}">🔑 Ganti Password</a>
                <a role="menuitem" href="{{ route('switch') }}">🔄 Ganti Akun</a>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="menu-btn danger" role="menuitem">⎋ Keluar</button></form>
            </div>
        </details>
    </div></header>

    {{-- Navigasi bawah untuk HP (mudah dijangkau jempol) --}}
    <nav class="bottom-nav" aria-label="Menu utama (HP)">
        @foreach($tabs as [$r, , $short, $icon, $pat])
            <a href="{{ route($r) }}" @if(request()->routeIs($pat)) class="on" aria-current="page" @endif><svg class="ic"><use href="#{{ $icon }}"/></svg><span>{{ $short }}</span></a>
        @endforeach
        @if($extra)
            @php($extraOn = collect($extra)->contains(fn ($e) => request()->routeIs($e[4])))
            <button type="button" data-open-acct @class(['on' => $extraOn])><svg class="ic"><use href="#i-menu"/></svg><span>Lainnya</span></button>
        @else
            <button type="button" data-open-acct><svg class="ic"><use href="#i-menu"/></svg><span>Akun</span></button>
        @endif
    </nav>
    @endunless
@endauth
<main id="isi" @class(['wrap', 'wrap-bare' => $bare ?? false]) @isset($width) style="max-width:{{ $width }}" @endisset tabindex="-1">
    @include('partials.flash')
    @yield('content')
</main>
<script src="{{ asset('assets/app.js') }}?v={{ @filemtime(public_path('assets/app.js')) }}"></script>
</body>
</html>
