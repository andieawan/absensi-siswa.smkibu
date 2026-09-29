@extends('layouts.app', ['title' => 'Ganti Password'])
@section('content')
<div class="card" style="max-width:460px;margin:0 auto">
    <h1>Ganti Password</h1><p class="mut">Akun: <b>{{ auth()->user()->username }}</b></p>
    <form method="post" action="{{ route('password') }}" autocomplete="off">@csrf
        <div class="field"><label for="old">Password lama</label><input id="old" type="password" name="old" autocomplete="current-password" required></div>
        <div class="field"><label for="new">Password baru (min. 8 karakter)</label><input id="new" type="password" name="new" autocomplete="new-password" minlength="8" required></div>
        <div class="field"><label for="new_confirmation">Ulangi password baru</label><input id="new_confirmation" type="password" name="new_confirmation" autocomplete="new-password" required></div>
        <div class="row"><button class="btn btn-pri">Simpan Password</button><a class="btn" href="{{ route('dashboard') }}">Batal</a></div>
    </form>
</div>
@endsection
