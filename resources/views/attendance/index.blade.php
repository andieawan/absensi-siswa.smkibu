@extends('layouts.app', ['title' => 'Absensi'])
@section('content')
@php
    $ctx = ['mode' => $mode, 'class' => $classId, 'subject' => $subjectId, 'date' => $tanggal];
    $url = fn (array $o = []) => route('attendance', array_filter(array_merge($ctx, $o), fn ($v) => $v !== null));
    $hidden = fn () => collect($ctx)->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => '<input type="hidden" name="'.$k.'" value="'.e($v).'">')->implode('');
@endphp
<div class="head">
    <div>
        <h1>Absensi {{ $mode === 'wali' ? 'Harian (Wali Kelas)' : 'Per Mata Pelajaran' }}</h1>
        <small>{{ $class->name ?? '-' }} · {{ \App\Support\Dates::human($tanggal) }}</small>
    </div>
    <div class="row">
        <div class="seg">
            @if($isWali || auth()->user()->isAdmin())<a @class(['on' => $mode === 'wali']) href="{{ route('attendance', ['mode' => 'wali']) }}">Wali Kelas</a>@endif
            <a @class(['on' => $mode === 'mapel']) href="{{ route('attendance', ['mode' => 'mapel']) }}">Per Mapel</a>
        </div>
        <div class="seg">
            <a @class(['on' => $tab === 'input']) href="{{ $url() }}">Input</a>
            <a @class(['on' => $tab === 'riwayat']) href="{{ $url(['tab' => 'riwayat']) }}">Riwayat &amp; Delegasi</a>
        </div>
    </div>
</div>

<form method="get" action="{{ route('attendance') }}" class="card">
    <input type="hidden" name="mode" value="{{ $mode }}">@if($tab !== 'input')<input type="hidden" name="tab" value="{{ $tab }}">@endif
    <div class="fields">
        <div><label for="class">Kelas</label><select id="class" name="class" data-auto>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $classId)>{{ $c->name }}</option>@endforeach</select></div>
        @if($mode === 'mapel')
            <div><label for="subject">Mata Pelajaran</label><select id="subject" name="subject" data-auto>@foreach($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@endforeach</select></div>
        @endif
        <div><label for="date">Tanggal</label><input id="date" type="date" name="date" value="{{ $tanggal }}" max="{{ \App\Support\Dates::today() }}" onchange="this.form.submit()"></div>
        <noscript><div><button class="btn">Tampilkan</button></div></noscript>
    </div>
</form>

@unless($auth['allowed'])<div class="alert alert-warn">{{ $auth['error'] ?? 'Anda tidak berwenang pada kelas/mapel ini.' }}</div>@endunless

@if($tab === 'input')
    @if($students->isEmpty())
        <div class="card"><div class="empty">Tidak ada siswa aktif di kelas ini.</div></div>
    @else
        @if($existing->isNotEmpty())<div class="alert alert-info">Sesi ini sudah tercatat. Menyimpan lagi akan memperbarui data (bukan menggandakan).</div>@endif
        <form method="post" action="{{ route('attendance.store') }}" class="card card-tight">
            @csrf {!! $hidden() !!}
            <div class="row" style="padding:12px 14px;border-bottom:1px solid var(--line);justify-content:space-between">
                <div class="row"><button type="button" class="btn btn-sm" data-setall="H">Set Semua Hadir</button>
                    <span id="recount" class="mono" style="font-size:12px">H <b data-c="H">0</b> · I <b data-c="I">0</b> · S <b data-c="S">0</b> · A <b data-c="A">0</b></span></div>
                <small>{{ $students->count() }} siswa aktif</small>
            </div>
            @foreach($students as $i => $s)
                @include('partials.hisa', ['s' => $s, 'i' => $i, 'cur' => $existing[$s->id] ?? null])
            @endforeach
            <div class="sticky-save"><small>Aturan: input maksimal 7 hari ke belakang untuk non-admin.</small>
                <button class="btn btn-pri" @disabled(! $auth['allowed'])>Simpan Presensi</button></div>
        </form>
    @endif
@else
    @if($mode === 'wali')
        <div class="card">
            <h2>Delegasi Ketua Kelas</h2>
            <p class="mut" style="font-size:12px">Buat tautan sementara (maks. 24 jam) agar ketua kelas dapat mengisi absen harian tanpa login.</p>
            @if($newToken)
                <div class="alert alert-success">Bagikan tautan ini ke ketua kelas:</div>
                <code class="link" id="lnk">{{ route('delegation', $newToken) }}</code>
                <p><button type="button" class="btn btn-sm" data-copy="#lnk">Salin Tautan</button></p>
            @endif
            <form method="post" action="{{ route('attendance.delegate') }}" class="row">@csrf {!! $hidden() !!}
                <select name="hours" style="width:auto">@foreach([1, 3, 6, 12, 24] as $h)<option value="{{ $h }}" @selected($h === 24)>Berlaku {{ $h }} jam</option>@endforeach</select>
                <button class="btn btn-pri" @disabled(! $auth['allowed'])>Buat Tautan Delegasi</button>
            </form>
            @if($activeTokens->isNotEmpty())
                <div style="margin-top:12px"><small class="mut">Tautan yang masih aktif:</small>
                @foreach($activeTokens as $t)
                    <div class="row" style="justify-content:space-between;border-top:1px solid var(--line2);padding:6px 0">
                        <span><code class="mono" style="font-size:11px">{{ substr($t->token, 0, 12) }}…</code> <small>berlaku sampai {{ \App\Support\Dates::local($t->expiryMillis()) }} WIB</small></span>
                        <form method="post" action="{{ route('attendance.revoke', $t->token) }}" class="inline" data-confirm="Cabut tautan ini? Ketua kelas tidak bisa memakainya lagi.">@csrf {!! $hidden() !!}<button class="btn btn-sm btn-danger">Cabut</button></form>
                    </div>
                @endforeach</div>
            @endif
        </div>
    @endif
    <div class="card card-tight">
        <div class="row" style="padding:14px 16px;justify-content:space-between;border-bottom:1px solid var(--line)">
            <div><h2>Riwayat Sesi Absensi</h2><small>Sesi lebih dari 7 hari terkunci (hanya Admin yang bisa mengubah/menghapus).</small></div>
            @if($classId)<a class="btn btn-sm" href="{{ route('export.attendance', array_filter(['class' => $classId, 'subject' => $subjectId])) }}">Unduh Excel</a>@endif
        </div>
        @if(! $sessions)
            <div class="empty">Belum ada sesi tercatat.</div>
        @else
        <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th>Dicatat via</th><th class="r">Aksi</th></tr></thead><tbody>
        @foreach($sessions as $s)
            @php($locked = ! \App\Services\Rules::withinEditWindow($s['tanggal']) && ! auth()->user()->isAdmin())
            <tr>
                <td><a href="{{ $url(['date' => $s['tanggal']]) }}">{{ \App\Support\Dates::human($s['tanggal']) }}</a></td>
                <td class="c mono">{{ $s['H'] }}</td><td class="c mono">{{ $s['I'] }}</td><td class="c mono warn">{{ $s['S'] }}</td><td class="c mono bad">{{ $s['A'] }}</td>
                <td>{{ \App\Models\Attendance::VIA[$s['via']] ?? $s['via'] }}</td>
                <td class="r">@if($locked)<span class="badge">🔒 Terkunci</span>@else
                    <form method="post" action="{{ route('attendance.destroy') }}" class="inline" data-confirm="Hapus seluruh presensi tanggal {{ $s['tanggal'] }}?">@csrf {!! $hidden() !!}<input type="hidden" name="tgl" value="{{ $s['tanggal'] }}"><button class="btn btn-sm btn-danger">Hapus</button></form>
                @endif</td>
            </tr>
        @endforeach
        </tbody></table></div>
        @endif
    </div>
@endif
@endsection
