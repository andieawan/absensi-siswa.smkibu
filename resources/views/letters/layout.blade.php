@extends('layouts.app', ['title' => $title])
@section('content')
<div class="card noprint">
    @hasSection('toolbar')
        <form method="get" class="fields">@yield('toolbar')
            <div class="row"><button class="btn">Perbarui</button><button type="button" class="btn btn-pri" onclick="window.print()">Cetak / Simpan PDF</button></div>
        </form>
        <small>Alamat dan NIP tidak diisi otomatis; lengkapi manual bila diperlukan.</small>
    @else
        <button type="button" class="btn btn-pri" onclick="window.print()">Cetak / Simpan PDF</button>
    @endif
</div>
<article class="letter">@yield('letter')</article>
@endsection
