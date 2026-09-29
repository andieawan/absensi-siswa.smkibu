@extends('layouts.app', ['title' => 'Instalasi', 'bare' => true])
@section('content')
<div class="card login">
    <div class="logo">A</div>
    <h1>Instalasi Aplikasi</h1>
    <p class="mut">Langkah ini membuat tabel database dan akun Administrator pertama dari <code>ADMIN_USERNAME</code> / <code>ADMIN_PASSWORD</code> di file <code>.env</code>. Halaman ini otomatis tidak bisa dibuka lagi setelah ada akun.</p>
    @if($ready)
        <form method="post" action="{{ route('install') }}">@csrf<button class="btn btn-pri" style="width:100%">Pasang Sekarang</button></form>
    @else
        <div class="alert alert-warn">Isi <b>ADMIN_USERNAME</b> dan <b>ADMIN_PASSWORD</b> (minimal 10 karakter) di file <code>.env</code>, lalu muat ulang halaman ini.</div>
    @endif
</div>
@endsection
