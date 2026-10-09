@extends('layouts.app', ['title' => 'Presensi BK'])
@section('content')
@php($cls = $classes->firstWhere('id', $classId))
<div class="head"><div><h1>Presensi BK</h1><small>Input absensi manual oleh BK, tautan ketua kelas, dan rekap ketidakhadiran per kelas.</small></div>
    @if($classId)<a class="btn btn-sm" href="{{ route('export.bk', ['class' => $classId]) }}">Unduh Rekap Excel</a>@endif</div>
@include('bk._tabs')
<form method="get" action="{{ route('bk') }}" class="card">
    <div class="fields"><div><label for="class">Kelas</label><select id="class" name="class" data-auto>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $classId)>{{ $c->name }}</option>@endforeach</select></div></div>
</form>
<form method="post" action="{{ route('bk.store') }}" class="card">@csrf
    <h2>Input Absensi Manual (BK)</h2>
    <div class="fields">
        <div><label for="student">Siswa</label><select id="student" name="student">@foreach($students as $s)<option value="{{ $s->id }}" @selected(old('student') == $s->id)>{{ $s->nama }} ({{ $s->nis }})</option>@endforeach</select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="I">Izin (I)</option><option value="S">Sakit (S)</option><option value="A">Alpa (A)</option><option value="H">Hadir (H)</option></select></div>
        <div><label for="date">Tanggal</label><input id="date" type="date" name="date" value="{{ old('date', \App\Support\Dates::today()) }}" max="{{ \App\Support\Dates::today() }}" required></div>
        <div style="grid-column:span 2"><label for="notes">Catatan</label><input id="notes" type="text" name="notes" maxlength="200" value="{{ old('notes') }}" placeholder="mis. Konseling BK: izin dispensasi pendampingan keluarga"></div>
        <div><button class="btn btn-pri" @disabled($students->isEmpty())>Simpan Absensi BK</button></div>
    </div>
</form>
@if($cls)
<div class="card">
    <h2>Tautan Ketua Kelas — {{ $cls->name }}</h2>
    <p class="mut" style="font-size:12px">Guru BK dapat membuat tautan sementara (maks. 24 jam) untuk kelas mana pun, mis. saat wali kelas berhalangan.</p>
    @if($newToken)
        <div class="share-box">
            <b>✓ Tautan siap dibagikan ke ketua kelas</b>
            <code class="link" id="lnk">{{ route('delegation', $newToken) }}</code>
            <div class="row">
                <button type="button" class="btn btn-sm" data-copy="#lnk">Salin Tautan</button>
                <a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="{{ \App\Support\WhatsApp::share('Link absensi harian kelas '.$cls->name.' (berlaku sementara): '.route('delegation', $newToken)) }}">Kirim via WhatsApp</a>
            </div>
        </div>
    @endif
    <form method="post" action="{{ route('bk.delegate') }}" class="row">@csrf <input type="hidden" name="class" value="{{ $cls->id }}">
        <select name="hours" style="width:auto" aria-label="Masa berlaku">@foreach([1, 3, 6, 12, 24] as $h)<option value="{{ $h }}" @selected($h === 24)>Berlaku {{ $h }} jam</option>@endforeach</select>
        <button class="btn btn-pri">Buat Tautan Delegasi</button>
    </form>
    @if($tokens->isNotEmpty())
        <div style="margin-top:12px"><small class="mut">Tautan yang masih aktif:</small>
        @foreach($tokens as $t)
            <div class="row" style="justify-content:space-between;border-top:1px solid var(--line2);padding:6px 0">
                <span><code class="mono" style="font-size:11px">{{ substr($t->token, 0, 12) }}…</code> <small>berlaku sampai {{ \App\Support\Dates::local($t->expiryMillis()) }} WIB</small></span>
                <form method="post" action="{{ route('bk.revoke', $t->token) }}" class="inline" data-confirm="Cabut tautan ini? Ketua kelas tidak bisa memakainya lagi.">@csrf<button class="btn btn-sm btn-danger">Cabut</button></form>
            </div>
        @endforeach</div>
    @endif
</div>
@endif
<div class="card card-tight"><div style="padding:14px 16px"><h2>Rekap Ketidakhadiran Kelas</h2><small>Diurutkan dari ketidakhadiran terbanyak</small></div>
    @if($recap->isEmpty())<div class="empty">Tidak ada siswa aktif.</div>@else
    <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Nama</th><th>NIS</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th class="c">Total Absen</th><th class="r">Aksi</th></tr></thead><tbody>
    @foreach($recap as $r)
        <tr><td class="card-title"><b>{{ $r['s']->nama }}</b></td><td class="mono mut" data-label="NIS">{{ $r['s']->nis }}</td><td class="c mono" data-label="Hadir">{{ $r['h'] }}</td><td class="c mono" data-label="Izin">{{ $r['i'] }}</td><td class="c mono warn" data-label="Sakit">{{ $r['sk'] }}</td><td class="c mono bad" data-label="Alpa">{{ $r['a'] }}</td><td class="c mono" data-label="Total absen"><b>{{ $r['abs'] }}</b></td>
            <td class="r"><a href="{{ route('students', ['id' => $r['s']->id]) }}">Riwayat</a> · <a href="{{ route('bk.records', ['kasus', 'siswa' => $r['s']->id]) }}">Catatan BK</a> · <select aria-label="Cetak surat untuk {{ $r['s']->nama }}" style="width:auto;padding:4px 8px;min-height:32px;font-size:13px" onchange="if(this.value){window.open(this.value,'_blank');this.selectedIndex=0}"><option value="">🖨 Cetak surat…</option>
                <option value="{{ route('letters.summons', $r['s']) }}">Surat Panggilan Wali Murid</option><option value="{{ route('letters.warning', $r['s']) }}">Surat Peringatan</option>
                @foreach(['teguran' => 'Surat Teguran Tertulis', 'pernyataan-berhenti' => 'Pernyataan Siap Diberhentikan', 'pernyataan-mundur' => 'Pernyataan Mengundurkan Diri', 'berita-acara' => 'Berita Acara Pemanggilan Ortu', 'izin' => 'Surat Izin Meninggalkan Sekolah'] as $jk => $jl)<option value="{{ route('letters.form', [$jk, $r['s']]) }}">{{ $jl }}</option>@endforeach
            </select></td></tr>
    @endforeach
    </tbody></table></div>@endif
</div>
@endsection
