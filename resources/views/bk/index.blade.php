@extends('layouts.app', ['title' => 'Integrasi BK'])
@section('content')
<div class="head"><div><h1>Integrasi Bimbingan Konseling</h1><small>Input absensi manual oleh BK dan rekap ketidakhadiran per kelas.</small></div>
    @if($classId)<a class="btn btn-sm" href="{{ route('export.bk', ['class' => $classId]) }}">Unduh Rekap Excel</a>@endif</div>
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
<div class="card card-tight"><div style="padding:14px 16px"><h2>Rekap Ketidakhadiran Kelas</h2><small>Diurutkan dari ketidakhadiran terbanyak</small></div>
    @if($recap->isEmpty())<div class="empty">Tidak ada siswa aktif.</div>@else
    <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Nama</th><th>NIS</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th class="c">Total Absen</th><th class="r">Aksi</th></tr></thead><tbody>
    @foreach($recap as $r)
        <tr><td class="card-title"><b>{{ $r['s']->nama }}</b></td><td class="mono mut" data-label="NIS">{{ $r['s']->nis }}</td><td class="c mono" data-label="Hadir">{{ $r['h'] }}</td><td class="c mono" data-label="Izin">{{ $r['i'] }}</td><td class="c mono warn" data-label="Sakit">{{ $r['sk'] }}</td><td class="c mono bad" data-label="Alpa">{{ $r['a'] }}</td><td class="c mono" data-label="Total absen"><b>{{ $r['abs'] }}</b></td>
            <td class="r"><a href="{{ route('students', ['id' => $r['s']->id]) }}">Riwayat</a> · <a target="_blank" href="{{ route('letters.summons', $r['s']) }}">Surat panggilan</a></td></tr>
    @endforeach
    </tbody></table></div>@endif
</div>
@endsection
