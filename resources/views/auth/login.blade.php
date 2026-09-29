@extends('layouts.app', ['title' => $switching ? 'Ganti Akun' : 'Masuk', 'bare' => ! $switching, 'width' => $switching ? '520px' : null])
@section('content')
<div class="card login">
    <div class="logo">A</div>
    <h1>{{ $switching ? 'Ganti Akun' : 'Absensi Siswa' }}</h1>
    <p class="mut">{{ $settings->school_name }}<br>{{ $switching ? 'Masukkan username dan password akun tujuan.' : 'Masuk dengan akun yang diberikan administrator.' }}</p>
    @if($needsInstall ?? false)<div class="alert alert-info">Aplikasi belum dipasang. <a href="{{ route('install') }}">Buka halaman instalasi →</a></div>@endif
    <form method="post" action="{{ $switching ? route('switch') : route('login') }}" autocomplete="off">
        @csrf
        <div class="field"><label for="u">Username</label><input id="u" type="text" name="username" value="{{ old('username') }}" autocapitalize="none" autocomplete="username" required autofocus></div>
        <div class="field"><label for="pw">Password</label><input id="pw" type="password" name="password" autocomplete="current-password" required></div>
        <button class="btn btn-pri" style="width:100%">{{ $switching ? 'Pindah Akun' : 'Masuk' }}</button>
    </form>
    @unless($switching)<button type="button" class="btn btn-sm hide" id="pwa-install" style="width:100%;margin-top:10px">📲 Pasang Aplikasi di Perangkat Ini</button>@endunless
    @if($switching)<p style="margin-top:14px"><a href="{{ route('dashboard') }}">&larr; Batal, kembali</a></p>@endif
</div>
@endsection
