@extends('layouts.app', ['title' => 'Riwayat Siswa'])
@section('content')
@php($rc = fn ($r) => $r >= 90 ? 'ok' : ($r >= 80 ? 'warn' : 'bad'))
<div class="head"><div><h1>Riwayat Siswa</h1><small>Profil, kehadiran, nilai, dan akses wali murid per siswa.</small></div></div>
<div @class(['grid', 's360', 'has-sel' => (bool) $student]) style="grid-template-columns:minmax(230px,300px) 1fr;align-items:start">
    <aside class="card card-tight" id="daftar-pilih" data-swap>
        <form method="get" action="{{ route('students') }}" style="padding:12px;border-bottom:1px solid var(--line)">
            @if($student)<input type="hidden" name="id" value="{{ $student->id }}">@endif
            <div class="field"><input type="search" name="q" value="{{ $q }}" placeholder="Cari nama / NIS…" aria-label="Cari siswa"></div>
            <select name="class" data-auto aria-label="Filter kelas"><option value="0">Semua kelas</option>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $fc)>{{ $c->name }}</option>@endforeach</select>
            <noscript><button class="btn btn-sm" style="margin-top:8px">Cari</button></noscript>
        </form>
        <div style="padding:8px 14px;font-size:12.5px;color:var(--mut)" aria-live="polite"><b class="mono">{{ number_format($list->total()) }}</b> siswa{{ $q ? ' cocok' : '' }}</div>
        <div style="max-height:62vh;overflow:auto">
            @forelse($list as $s)
                <a href="{{ route('students', array_filter(['id' => $s->id, 'q' => $q ?: null, 'class' => $fc ?: null, 'page' => request('page')])) }}" class="stu" style="color:inherit;text-decoration:none;{{ $student && $s->id === $student->id ? 'background:#eef2ff' : '' }}">
                    <div class="nm"><b>{{ $s->nama }}</b><small>{{ $s->nis }} · {{ $s->schoolClass->name ?? '-' }}</small></div></a>
            @empty
                <div class="empty">Tidak ada siswa cocok.</div>
            @endforelse
        </div>
        @include('partials.pager', ['p' => $list])
    </aside>

    <section>
    @if(! $student)
        <div class="card hide-sm"><div class="empty">Cari lalu pilih siswa dari daftar di kiri untuk melihat riwayatnya.</div></div>
    @else
        <a class="back-link only-mobile" href="{{ route('students', array_filter(['q' => $q ?: null, 'class' => $fc ?: null])) }}">‹ Kembali ke daftar siswa</a>
        <div class="card">
            <div class="head" style="margin-bottom:12px">
                <div><h1>{{ $student->nama }}</h1><small class="mono">{{ $student->nis }}</small> · <small>{{ $student->schoolClass->name ?? '-' }} · {{ $student->jk === 'L' ? 'Laki-laki' : 'Perempuan' }}</small>
                    <span @class(['badge', 'b-ok' => $student->status === 'aktif', 'b-warn' => $student->status !== 'aktif'])>{{ $student->status }}</span></div>
                @if($canLetter)<details class="more" data-close-outside style="position:relative"><summary class="btn btn-sm">🖨 Cetak Surat ▾</summary>
                    <div class="card" style="position:absolute;right:0;z-index:20;min-width:260px;padding:6px;margin-top:4px;display:grid;gap:2px">
                        <a href="{{ route('letters.summons', $student) }}" target="_blank">Surat Panggilan Wali Murid</a>
                        <a href="{{ route('letters.warning', $student) }}" target="_blank">Surat Peringatan</a>
                        <a href="{{ route('letters.form', ['teguran', $student]) }}" target="_blank">Surat Teguran Tertulis</a>
                        <a href="{{ route('letters.form', ['pernyataan-berhenti', $student]) }}" target="_blank">Pernyataan Siap Diberhentikan</a>
                        <a href="{{ route('letters.form', ['pernyataan-mundur', $student]) }}" target="_blank">Pernyataan Mengundurkan Diri</a>
                        <a href="{{ route('letters.form', ['berita-acara', $student]) }}" target="_blank">Berita Acara Pemanggilan Ortu</a>
                        <a href="{{ route('letters.form', ['izin', $student]) }}" target="_blank">Surat Izin Meninggalkan Sekolah</a>
                    </div></details>@endif
            </div>
            <div class="grid g5">
                <div class="kpi"><small>Kehadiran</small><div class="n mono {{ $rc($stats['rate']) }}">{{ number_format($stats['rate'], 1) }}%</div></div>
                <div class="kpi"><small>Hadir</small><div class="n mono">{{ $stats['hadir'] }}</div></div>
                <div class="kpi"><small>Izin</small><div class="n mono">{{ $stats['izin'] }}</div></div>
                <div class="kpi"><small>Sakit</small><div class="n mono warn">{{ $stats['sakit'] }}</div></div>
                <div class="kpi"><small>Alpa</small><div class="n mono bad">{{ $stats['alpa'] }}</div></div>
            </div>
            @if($attention['category'])<p style="margin-top:12px"><span class="badge b-warn">Perlu perhatian: {{ \App\Services\Analytics::CATEGORIES[$attention['category']] }}</span></p>@endif
            @if($canLetter)
                @php($portal = $newToken ?: $tokens->firstWhere('status', 'aktif')?->token)
                @php($waSum = \App\Support\WhatsApp::link($student->telp_ortu, \App\Support\WhatsApp::summaryMessage($student, $stats, $portal ? route('parent', $portal) : null, auth()->user()->nama)))
                <div class="contact-row">
                    <span>👪 <b>Orang tua/wali:</b> {{ $student->nama_ortu ?: '-' }} · <span class="mono">{{ \App\Support\WhatsApp::display($student->telp_ortu) }}</span></span>
                    @if($waSum)<a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="{{ $waSum }}">Kirim ringkasan via WhatsApp</a>
                    @else<small class="mut">Nomor HP orang tua belum diisi @can('admin')— <a href="{{ route('admin.students', ['q' => $student->nis]) }}">isi di Data Siswa</a>@else (hubungi admin)@endcan.</small>@endif
                </div>
            @endif
        </div>

        @foreach($patterns as $a)
            <div class="alert alert-warn">{{ $a['status_type'] }} pola absen berkala: selalu absen pada hari {{ $a['day_of_week'] }} ({{ $a['count'] }}x) — {{ implode(', ', $a['dates']) }}</div>
        @endforeach

        <div class="card">
            <h2>Syarat Kehadiran Minimal 85%</h2>
            @if($stats['meets'])
                <div class="alert alert-success">Memenuhi syarat: kehadiran {{ $stats['rate'] }}% (≥ 85%). Berhak mengikuti ujian akhir dan pengesahan akademik.</div>
            @else
                <div class="alert alert-error">Belum memenuhi syarat: kehadiran {{ $stats['rate'] }}% (&lt; 85%). Defisit {{ $stats['deficit'] }} sesi kehadiran. Pengesahan ditangguhkan; wajib pembinaan BK atau dispensasi Kepala Sekolah.</div>
            @endif
            <form method="post" action="{{ route('students.clearance', $student) }}" class="row" style="align-items:flex-end">@csrf
                @if($canOverride)
                    <label class="chk" style="margin:0"><input type="checkbox" name="override" value="1"> Dispensasi khusus (Admin/Kepsek)</label>
                    <div style="flex:1;min-width:220px"><label for="reason">Alasan dispensasi</label><input id="reason" type="text" name="reason" maxlength="200" placeholder="mis. sakit rawat inap dengan surat dokter"></div>
                @endif
                <button class="btn btn-pri">Uji Pengesahan Akademik</button>
            </form>
        </div>

        <div class="card card-tight"><div style="padding:14px 16px"><h2>Log Ketidakhadiran ({{ $absences->count() }})</h2></div>
            @if($absences->isEmpty())<div class="empty">Tidak ada catatan ketidakhadiran. 🎉</div>@else
            <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th>Status</th><th>Sesi</th><th>Catatan</th></tr></thead><tbody>
            @foreach($absences as $r)
                <tr><td>{{ \App\Support\Dates::human($r->tanggal) }}</td>
                    <td><span @class(['badge', 'b-warn' => $r->status === 'S', 'b-bad' => $r->status === 'A'])>{{ \App\Models\Attendance::STATUS[$r->status] }}</span></td>
                    <td>{{ $r->subject_id === null ? 'Harian' : ($subjectNames[$r->subject_id] ?? 'Mapel #'.$r->subject_id) }}</td><td>{{ $r->notes ?? '-' }}</td></tr>
            @endforeach
            </tbody></table></div>@endif
        </div>

        <div class="card card-tight"><div style="padding:14px 16px"><h2>Dossier Nilai</h2></div>
            @if($grades->isEmpty())<div class="empty">Belum ada nilai.</div>@else
            <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th>Mapel</th><th>Kegiatan</th><th class="c">Nilai</th></tr></thead><tbody>
            @foreach($grades as $g)
                @php($a = $acts[$g->activity_id])
                <tr><td class="mono">{{ $a->tanggal_kegiatan }}</td><td>{{ $subjectNames[$a->subject_id] ?? '-' }}</td><td>{{ $a->nama_kegiatan }}</td><td @class(['c', 'mono', 'low' => $g->isLow()])><b>{{ $g->nilai }}</b></td></tr>
            @endforeach
            </tbody></table></div>@endif
        </div>

        @if($bkRecords !== null)
        <div class="card card-tight"><div class="row" style="padding:14px 16px;justify-content:space-between"><h2>Catatan BK ({{ $bkRecords->count() }})</h2>
            @if(\App\Services\BkService::isCounselor(auth()->user()))<a class="btn btn-sm" href="{{ route('bk.records', ['kasus', 'siswa' => $student->id]) }}">+ Catat</a>@endif</div>
            @if($bkRecords->isEmpty())<div class="empty">Belum ada catatan BK untuk siswa ini.</div>@else
            @foreach($bkRecords as $r)
                @php($mod = \App\Support\BkModules::get($r->jenis))
                <a class="stu" style="color:inherit" href="{{ route('bk.records', [$r->jenis, 'siswa' => $student->id]) }}">
                    <span aria-hidden="true">{{ $mod['icon'] ?? '•' }}</span>
                    <span class="nm"><b>{{ \App\Services\BkService::isMasked(auth()->user(), $r) ? '🔒 Kasus rahasia — ditangani BK' : $r->judul }}</b><small>{{ $mod['label'] ?? $r->jenis }}{{ $r->kategori && ! \App\Services\BkService::isMasked(auth()->user(), $r) ? ' · '.$r->kategori : '' }} · {{ \App\Support\Dates::human($r->tanggal) }}</small></span>
                    @if($r->status)<span @class(['badge', 'b-warn' => $r->status === 'Proses', 'b-ok' => $r->status === 'Selesai'])>{{ $r->status }}</span>@endif
                </a>
            @endforeach
            @endif
        </div>
        @endif

        @if($canParent)
        <div class="card"><h2>Akses Portal Wali Murid</h2>
            <p class="mut" style="font-size:12px">Tautan baca-saja untuk orang tua: hanya menampilkan kehadiran siswa ini (tanpa nilai atau data siswa lain). Dapat dicabut kapan saja.</p>
            @if($newToken)
                <div class="alert alert-success">Kirim tautan ini ke orang tua/wali:</div>
                <code class="link" id="wm">{{ route('parent', $newToken) }}</code>
                <p class="row"><button type="button" class="btn btn-sm" data-copy="#wm">Salin Tautan</button>
                    @php($waP = \App\Support\WhatsApp::link($student->telp_ortu, 'Assalamu\'alaikum Bapak/Ibu, berikut tautan untuk memantau kehadiran ananda '.$student->nama.': '.route('parent', $newToken)."\n\n".$settings->school_name))
                    <a class="btn btn-sm btn-wa" target="_blank" rel="noopener" href="{{ $waP ?? \App\Support\WhatsApp::share('Tautan kehadiran ananda '.$student->nama.': '.route('parent', $newToken)) }}">Kirim via WhatsApp</a></p>
            @endif
            <form method="post" action="{{ route('students.parent.create', $student) }}" style="margin-bottom:10px">@csrf<button class="btn">Buat Tautan Baru</button></form>
            @foreach($tokens as $t)
                <div class="row" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line2)">
                    <div><code class="mono" style="font-size:11px">{{ substr($t->token, 0, 12) }}…</code> <span @class(['badge', 'b-ok' => $t->status === 'aktif'])>{{ $t->status }}</span> <small>dibuat {{ substr($t->created_at, 0, 10) }}</small></div>
                    @if($t->status === 'aktif')<form method="post" action="{{ route('students.parent.revoke', $t->token) }}" class="inline" data-confirm="Cabut tautan ini? Orang tua tidak bisa membukanya lagi.">@csrf<button class="btn btn-sm btn-danger">Cabut</button></form>@endif
                </div>
            @endforeach
        </div>
        @endif
    @endif
    </section>
</div>
@push('head')<style>@media(max-width:760px){.s360{grid-template-columns:1fr!important}.s360.has-sel>aside{display:none}}</style>@endpush
@endsection
