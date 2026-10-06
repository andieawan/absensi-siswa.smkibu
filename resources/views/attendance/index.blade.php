@extends('layouts.app', ['title' => 'Absensi'])
@section('content')
@php
    $ctx = ['mode' => $mode, 'class' => $classId, 'subject' => $subjectId, 'date' => $tanggal];
    $url = fn (array $o = []) => route('attendance', array_filter(array_merge($ctx, $o), fn ($v) => $v !== null));
    $hidden = fn () => collect($ctx)->filter(fn ($v) => $v !== null)->map(fn ($v, $k) => '<input type="hidden" name="'.$k.'" value="'.e($v).'">')->implode('');
@endphp
@php
    $today = \App\Support\Dates::today();
    $shift = fn (int $d) => gmdate('Y-m-d', strtotime($tanggal.' UTC') + $d * 86400);
@endphp
<div class="head">
    <div>
        <h1>Absensi {{ $mode === 'wali' ? 'Harian' : 'Per Mapel' }}</h1>
        <small>{{ $class->name ?? 'Belum ada kelas' }} · {{ \App\Support\Dates::human($tanggal) }}@if($tanggal === $today) <span class="badge b-ok">Hari ini</span>@endif</small>
    </div>
    <div class="row">
        @if($isWali || auth()->user()->isAdmin())
        <div class="seg" role="group" aria-label="Jenis absensi">
            <a @class(['on' => $mode === 'wali']) href="{{ route('attendance', ['mode' => 'wali']) }}" @if($mode === 'wali') aria-current="page" @endif>Wali Kelas</a>
            <a @class(['on' => $mode === 'mapel']) href="{{ route('attendance', ['mode' => 'mapel']) }}" @if($mode === 'mapel') aria-current="page" @endif>Per Mapel</a>
        </div>
        @endif
        <div class="seg" role="group" aria-label="Bagian">
            <a @class(['on' => $tab === 'input']) href="{{ $url() }}" @if($tab === 'input') aria-current="page" @endif>Isi Absensi</a>
            <a @class(['on' => $tab === 'riwayat']) href="{{ $url(['tab' => 'riwayat']) }}" @if($tab === 'riwayat') aria-current="page" @endif>{{ $mode === 'wali' ? 'Riwayat & Ketua Kelas' : 'Riwayat' }}</a>
        </div>
        @if($mode === 'wali' && $classId)<a class="btn btn-sm" href="{{ route('recap', ['class' => $classId]) }}">Rekap Rapor</a>@endif
    </div>
</div>

<form method="get" action="{{ route('attendance') }}" class="card filter-card">
    <input type="hidden" name="mode" value="{{ $mode }}">@if($tab !== 'input')<input type="hidden" name="tab" value="{{ $tab }}">@endif
    <div class="fields">
        @if($mode === 'mapel')
            <div><label for="subject">Mata Pelajaran</label><select id="subject" name="subject" data-auto data-class-map='@json($classMap)'>@forelse($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@empty<option value="">— belum ada mapel —</option>@endforelse</select></div>
        @endif
        @if($classes->count() > 1 || $mode === 'mapel')
            <div><label for="class">Kelas</label><select id="class" name="class" data-auto>@forelse($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $classId)>{{ $c->name }}</option>@empty<option value="">— belum ada kelas —</option>@endforelse</select></div>
        @else
            <input type="hidden" name="class" value="{{ $classId }}">
        @endif
        @if($tab === 'input')
        <div class="date-pick">
            <label for="date">Tanggal</label>
            <div class="date-row">
                <a class="btn btn-icon" href="{{ $url(['date' => $shift(-1)]) }}" aria-label="Hari sebelumnya" title="Hari sebelumnya">‹</a>
                <input id="date" type="date" name="date" value="{{ $tanggal }}" max="{{ $today }}" onchange="this.form.submit()">
                @if($tanggal < $today)<a class="btn btn-icon" href="{{ $url(['date' => $shift(1)]) }}" aria-label="Hari berikutnya" title="Hari berikutnya">›</a>@else<span class="btn btn-icon" aria-disabled="true">›</span>@endif
                @if($tanggal !== $today)<a class="btn btn-sm" href="{{ $url(['date' => $today]) }}">Hari ini</a>@endif
            </div>
        </div>
        @endif
        <noscript><div><button class="btn">Tampilkan</button></div></noscript>
    </div>
</form>

@unless($auth['allowed'])<div class="alert alert-warn"><span class="alert-ic" aria-hidden="true">!</span><span class="alert-txt">{{ $auth['error'] ?? 'Anda tidak berwenang pada kelas/mapel ini.' }}</span></div>@endunless

@if($tab === 'input')
    @if($students->isEmpty())
        <div class="card"><div class="empty"><b>Tidak ada siswa aktif di kelas ini.</b><br>@can('admin')Tambahkan siswa di <a href="{{ route('admin.students') }}">Admin Panel → Data Siswa</a>.@else Hubungi administrator untuk menambahkan data siswa.@endcan</div></div>
    @else
        @if($existing->isNotEmpty())
            <div class="alert alert-info"><span class="alert-ic" aria-hidden="true">i</span><span class="alert-txt"><b>Sudah diisi</b> untuk tanggal ini. Silakan ubah bila perlu — menyimpan lagi hanya memperbarui, tidak menggandakan.</span></div>
        @endif
        <form method="post" action="{{ route('attendance.store') }}" class="card card-tight" data-unsaved>
            @csrf {!! $hidden() !!}
            <div class="list-tools">
                <button type="button" class="btn btn-sm" data-setall="H">✓ Tandai semua Hadir</button>
                <span class="legend" aria-hidden="true"><i class="lg H">H</i>Hadir <i class="lg I">I</i>Izin <i class="lg S">S</i>Sakit <i class="lg A">A</i>Alpa</span>
            </div>
            @foreach($students as $i => $s)
                @include('partials.hisa', ['s' => $s, 'i' => $i, 'cur' => $existing[$s->id] ?? null])
            @endforeach
            <div class="sticky-save">
                <div id="recount" class="recount" aria-live="polite"><span class="rc H">H <b data-c="H">0</b></span><span class="rc I">I <b data-c="I">0</b></span><span class="rc S">S <b data-c="S">0</b></span><span class="rc A">A <b data-c="A">0</b></span><small class="hide-sm">dari {{ $students->count() }} siswa</small></div>
                <button class="btn btn-pri" data-busy="Menyimpan…" @disabled(! $auth['allowed'])>Simpan Absensi</button>
            </div>
        </form>
        <p class="hint">Guru dapat mengisi/mengubah absensi sampai 7 hari ke belakang. Siswa yang tidak diubah otomatis tercatat <b>Hadir</b>.</p>
        @php($absent = $mode === 'wali' ? $students->filter(fn ($s) => isset($existing[$s->id]) && $existing[$s->id]->status !== 'H') : collect())
        @if($absent->isNotEmpty())
        <div class="card card-tight">
            <div style="padding:14px 16px;border-bottom:1px solid var(--line2)"><h2>Kabari Orang Tua via WhatsApp</h2><small>Gratis — tombol membuka WhatsApp di HP/laptop Anda dengan pesan siap kirim. Periksa lalu tekan kirim.</small></div>
            @foreach($absent as $s)
                @php($att = $existing[$s->id])
                @php($wa = \App\Support\WhatsApp::link($s->telp_ortu, \App\Support\WhatsApp::attendanceMessage($s, $tanggal, $att->status, $att->notes, auth()->user()->nama)))
                <div class="stu"><span class="lg {{ $att->status }}" aria-label="{{ ['I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'][$att->status] ?? $att->status }}">{{ $att->status }}</span>
                    <span class="nm"><b>{{ $s->nama }}</b><small>{{ $s->nama_ortu ? 'Ortu: '.$s->nama_ortu.' · ' : '' }}{{ \App\Support\WhatsApp::display($s->telp_ortu) }}</small></span>
                    @if($wa)<a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="{{ $wa }}">Kirim WA</a>@else<span class="btn btn-sm" aria-disabled="true" title="Isi nomor HP orang tua di Admin → Data Siswa">No. HP belum ada</span>@endif
                </div>
            @endforeach
        </div>
        @endif
    @endif
@else
    @if($mode === 'wali')
        <div class="card">
            <h2>Delegasi Ketua Kelas</h2>
            <p class="mut" style="font-size:12px">Buat tautan sementara (maks. 24 jam) agar ketua kelas dapat mengisi absen harian tanpa login.</p>
            @if($newToken)
                <div class="share-box">
                    <b>✓ Tautan siap dibagikan ke ketua kelas</b>
                    <code class="link" id="lnk">{{ route('delegation', $newToken) }}</code>
                    <div class="row">
                        <button type="button" class="btn btn-sm" data-copy="#lnk">Salin Tautan</button>
                        <a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="https://wa.me/?text={{ rawurlencode('Link absensi harian kelas '.($class->name ?? '').' (berlaku sementara): '.route('delegation', $newToken)) }}">Kirim via WhatsApp</a>
                    </div>
                </div>
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
        <div class="card">
            <h2>Info Kehadiran untuk Wali Murid</h2>
            <p class="mut" style="font-size:12px">Satu tautan tetap untuk seluruh wali murid kelas ini (kirim ke grup WhatsApp kelas). Halamannya menampilkan siswa yang <b>tidak masuk</b> pada hari tertentu, diambil dari absen harian. Nama siswa terlihat oleh siapa pun yang memegang tautan, jadi bagikan hanya ke grup wali murid kelas.</p>
            @if(! $boardReady)
                <div class="alert alert-warn">Perlu pembaruan database dulu (Admin → Pengaturan → Perbarui Database Sekarang).</div>
            @elseif($board)
                <div class="share-box">
                    <code class="link" id="blnk">{{ $boardUrl }}</code>
                    <div class="row">
                        <button type="button" class="btn btn-sm" data-copy="#blnk">Salin Tautan</button>
                        <a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="{{ \App\Support\WhatsApp::share($boardMsg) }}">Kirim via WhatsApp</a>
                    </div>
                </div>
                <form method="post" action="{{ route('attendance.board.revoke', $board->token) }}" class="inline" data-confirm="Cabut tautan ini? Wali murid tidak bisa membukanya lagi.">@csrf {!! $hidden() !!}<button class="btn btn-sm btn-danger" style="margin-top:8px">Cabut Tautan</button></form>
            @else
                <form method="post" action="{{ route('attendance.board') }}">@csrf {!! $hidden() !!}<button class="btn btn-pri" @disabled(! $auth['allowed'])>Buat Tautan Info Kehadiran</button></form>
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
        <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Tanggal</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th>Dicatat via</th><th class="r">Aksi</th></tr></thead><tbody>
        @foreach($sessions as $s)
            @php($locked = ! \App\Services\Rules::withinEditWindow($s['tanggal']) && ! auth()->user()->isAdmin())
            <tr>
                <td class="card-title"><a href="{{ $url(['date' => $s['tanggal'], 'tab' => null]) }}">{{ \App\Support\Dates::human($s['tanggal']) }}</a></td>
                <td class="c mono" data-label="Hadir">{{ $s['H'] }}</td><td class="c mono" data-label="Izin">{{ $s['I'] }}</td><td class="c mono warn" data-label="Sakit">{{ $s['S'] }}</td><td class="c mono bad" data-label="Alpa">{{ $s['A'] }}</td>
                <td data-label="Dicatat via">{{ \App\Models\Attendance::VIA[$s['via']] ?? $s['via'] }}</td>
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
