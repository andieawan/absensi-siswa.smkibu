@extends('layouts.app', ['title' => 'Kenaikan Kelas'])
@section('content')
@include('admin._tabs')

<div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt">
    <b>Urutan yang disarankan di akhir tahun ajaran:</b>
    <ol class="steps" style="margin-top:4px">
        <li>Luluskan kelas tingkat tertinggi (mis. XII → <b>Lulus</b>).</li>
        <li>Naikkan XI → XII, lalu X → XI (mulai dari tingkat tertinggi agar kelas tujuan sudah kosong).</li>
        <li>Siswa yang <b>tidak naik</b> cukup dihilangkan centangnya — mereka tetap di kelas asal.</li>
        <li>Terakhir, aktifkan <b>tahun ajaran baru</b> di bawah, lalu atur ulang Wali Kelas bila berganti.</li>
    </ol>
    Riwayat absensi & nilai siswa <b>tidak hilang</b>. Database otomatis dicadangkan sebelum setiap proses.
</span></div>

<form method="get" action="{{ route('admin.promotion') }}" class="card filter-card">
    <div class="fields">
        <div><label for="dari">1. Pilih kelas asal</label>
            <select id="dari" name="dari" data-auto><option value="">— pilih kelas —</option>
                @foreach($classes as $c)<option value="{{ $c->id }}" @selected($from?->id === $c->id)>{{ $c->name }} ({{ $c->aktif_count }} siswa aktif)</option>@endforeach
            </select></div>
        <noscript><div style="align-self:end"><button class="btn">Tampilkan</button></div></noscript>
    </div>
</form>

@if($from)
    @if($students->isEmpty())
        <div class="card"><div class="empty">Tidak ada siswa aktif di kelas {{ $from->name }}.</div></div>
    @else
    <form method="post" action="{{ route('admin.promotion.run') }}" class="card card-tight" data-confirm="Proses kenaikan/kelulusan untuk siswa yang dicentang? Database akan dicadangkan lebih dulu.">
        @csrf <input type="hidden" name="dari" value="{{ $from->id }}">
        <div class="list-tools">
            <div><h2>2. Pilih siswa dari {{ $from->name }}</h2><small>Hilangkan centang untuk siswa yang tinggal kelas.</small></div>
            <label class="chk"><input type="checkbox" checked onchange="document.querySelectorAll('input[name=\'siswa[]\']').forEach(function(c){c.checked=this.checked}.bind(this))"> Pilih semua</label>
        </div>
        @foreach($students as $i => $s)
            <label class="stu chk" style="margin:0;font-weight:500"><input type="checkbox" name="siswa[]" value="{{ $s->id }}" @checked(! old('dari') || in_array($s->id, old('siswa', [])))>
                <span class="no">{{ $i + 1 }}</span><span class="nm"><b>{{ $s->nama }}</b><small>NIS {{ $s->nis }} · {{ $s->jk }}</small></span></label>
        @endforeach
        <div style="padding:14px 16px;border-top:1px solid var(--line)">
            <div class="fields">
                <div><label for="ke">3. Pindahkan ke</label>
                    <select id="ke" name="ke" required><option value="">— pilih tujuan —</option>
                        <option value="lulus" @selected(old('ke', $target) === 'lulus')>🎓 LULUS (keluar dari daftar aktif)</option>
                        @foreach($classes as $c)@if($c->id !== $from->id)<option value="{{ $c->id }}" @selected((string) old('ke', $target) === (string) $c->id)>{{ $c->name }}{{ $c->aktif_count ? ' — masih ada '.$c->aktif_count.' siswa' : ' — kosong' }}</option>@endif @endforeach
                    </select></div>
            </div>
            <label class="chk" style="margin-top:8px"><input type="checkbox" name="paham" value="1"> Kelas tujuan masih berisi siswa dan saya memang ingin menggabungkan</label>
            <button class="btn btn-pri" style="margin-top:10px" data-busy="Memproses…">Proses Kenaikan / Kelulusan</button>
        </div>
    </form>
    @endif
@endif

<div class="card">
    <h2>Tahun Ajaran</h2>
    <p class="mut" style="font-size:13px">Tahun ajaran aktif: <b>{{ $currentYear }}</b>, semester <b>{{ $settings->semester ?? \App\Support\TahunAjaran::semester() }}</b>. Semester Genap dapat diubah di <a href="{{ route('admin.settings') }}">Pengaturan</a>.</p>
    <form method="post" action="{{ route('admin.promotion.year') }}" class="row" data-confirm="Aktifkan tahun ajaran baru? Semua kelas akan diberi label tahun ajaran baru, semester Ganjil.">@csrf
        <input type="text" name="tahun_ajaran" value="{{ $nextYear }}" pattern="\d{4}/\d{4}" style="width:140px" aria-label="Tahun ajaran baru" required>
        <button class="btn btn-dark">Mulai Tahun Ajaran Baru</button>
    </form>
</div>
@endsection
