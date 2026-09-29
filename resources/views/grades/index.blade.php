@extends('layouts.app', ['title' => 'Nilai'])
@section('content')
@php
    $url = fn (array $o = []) => route('grades', array_filter(array_merge(['class' => $classId, 'subject' => $subjectId, 'tab' => $tab === 'input' ? null : $tab], $o), fn ($v) => $v !== null));
    $tipe = old('tipe', $editing->tipe_skala ?? 'angka');
@endphp
<div class="head">
    <div><h1>Penilaian Siswa</h1><small>Nilai angka 0–100 atau huruf A–E. Kegiatan dapat diedit/dihapus sampai 7 hari setelah diinput; setelah itu siswa yang belum punya nilai tetap bisa diisi susulan.</small></div>
    <div class="seg">
        <a @class(['on' => $tab === 'input']) href="{{ $url(['tab' => null]) }}">Input Nilai</a>
        <a @class(['on' => $tab === 'aktivitas']) href="{{ $url(['tab' => 'aktivitas']) }}">Kegiatan ({{ $acts->count() }})</a>
        <a @class(['on' => $tab === 'rekap']) href="{{ $url(['tab' => 'rekap']) }}">Rekap</a>
    </div>
</div>
<form method="get" action="{{ route('grades') }}" class="card">
    @if($tab !== 'input')<input type="hidden" name="tab" value="{{ $tab }}">@endif
    <div class="fields">
        <div><label for="class">Kelas</label><select id="class" name="class" data-auto>@foreach($classes as $c)<option value="{{ $c->id }}" @selected($c->id === $classId)>{{ $c->name }}</option>@endforeach</select></div>
        <div><label for="subject">Mata Pelajaran</label><select id="subject" name="subject" data-auto>@foreach($subjects as $s)<option value="{{ $s->id }}" @selected($s->id === $subjectId)>{{ $s->name }}</option>@endforeach</select></div>
    </div>
</form>
@unless($auth['allowed'])<div class="alert alert-warn">{{ $auth['error'] ?? 'Tidak berwenang.' }}</div>@endunless

@if($tab === 'input')
<form method="post" action="{{ route('grades.store') }}" class="card card-tight" data-unsaved data-scores>
    @csrf <input type="hidden" name="class" value="{{ $classId }}"><input type="hidden" name="subject" value="{{ $subjectId }}"><input type="hidden" name="act" value="{{ $editing->id ?? '' }}">
    <div style="padding:16px;border-bottom:1px solid var(--line)">
        @if($susulan)
            <div class="alert alert-warn"><b>Mode nilai susulan:</b> kegiatan “{{ $editing->nama_kegiatan }}” diinput lebih dari 7 hari lalu. Nilai yang sudah ada terkunci; hanya siswa yang <b>belum punya nilai</b> yang bisa diisi.</div>
        @elseif($editing)
            <div class="alert alert-info">Mengedit kegiatan: {{ $editing->nama_kegiatan }} — menyimpan akan menimpa nilai lama.</div>
        @endif
        <div class="fields">
            <div><label for="nama">Nama Kegiatan</label><input id="nama" type="text" name="nama" value="{{ old('nama', $editing->nama_kegiatan ?? '') }}" placeholder="mis. Ulangan Harian 1" maxlength="120" required @readonly($susulan)></div>
            <div><label for="tanggal">Tanggal</label><input id="tanggal" type="date" name="tanggal" value="{{ old('tanggal', $editing->tanggal_kegiatan ?? $today) }}" max="{{ $today }}" required @readonly($susulan)></div>
            <div><label for="tipe_skala">Skala</label>@if($susulan)<input type="hidden" name="tipe" value="{{ $tipe }}">@endif<select id="tipe_skala" name="tipe" @disabled($susulan)><option value="angka" @selected($tipe === 'angka')>Angka (0–100)</option><option value="huruf" @selected($tipe === 'huruf')>Huruf (A–E)</option></select></div>
        </div>
        @unless($susulan)<div class="row" style="margin-top:10px"><small>Isi cepat:</small>
            @foreach(['100', '85', '75', 'A', 'B', 'C'] as $v)<button type="button" class="btn btn-sm" data-quickfill="{{ $v }}">{{ $v }}</button>@endforeach
        </div>@endunless
    </div>
    @forelse($students as $i => $s)
        @php($lama = $editing ? ($vals[$editing->id][$s->id] ?? null) : null)
        @php($kunci = $susulan && $lama !== null)
        <div class="stu"><span class="no mono">{{ $i + 1 }}</span><div class="nm"><b>{{ $s->nama }}</b><small>{{ $s->nis }}@if($susulan && $lama === null) · <span class="warn">belum ada nilai</span>@endif</small></div>
            @if($kunci)
                <input class="mono" style="width:100px;text-align:center" type="text" value="{{ $lama }}" disabled aria-label="Nilai {{ $s->nama }} (terkunci)"><span class="badge" title="Terkunci">🔒</span>
            @else
                <input class="score mono" style="width:100px;text-align:center" type="text" name="nilai[{{ $s->id }}]" value="{{ old("nilai.{$s->id}", $lama ?? '') }}" aria-label="Nilai {{ $s->nama }}" autocomplete="off">
            @endif
        </div>
    @empty
        <div class="empty">Tidak ada siswa aktif di kelas ini.</div>
    @endforelse
    <div class="sticky-save"><div><b class="mono" data-filled>0</b> <small>dari {{ $students->count() }} siswa sudah bernilai</small><small class="hide-sm" style="display:block">Kosongkan nilai siswa yang belum mengumpulkan — bisa diisi susulan nanti (tidak dihitung rata-rata).</small></div>
        <div class="row">@if($editing)<a class="btn" href="{{ $url(['tab' => null]) }}">Batal Edit</a>@endif
            <button class="btn btn-pri" data-busy="Menyimpan…" @disabled(! $auth['allowed'] || $students->isEmpty())>{{ $susulan ? 'Simpan Nilai Susulan' : 'Simpan Nilai' }}</button></div></div>
</form>

@elseif($tab === 'aktivitas')
<div class="card card-tight">
    @if($acts->isEmpty())<div class="empty">Belum ada kegiatan penilaian untuk kombinasi ini.</div>@else
    <div class="scroll"><table class="tbl tbl-cards"><thead><tr><th>Tanggal</th><th>Kegiatan</th><th>Skala</th><th class="c">Siswa</th><th class="c">Rata-rata</th><th class="r">Aksi</th></tr></thead><tbody>
    @foreach($acts as $a)
        @php($v = $vals[$a->id] ?? [])
        @php($nums = array_filter($v, 'is_numeric'))
        @php($canDel = \App\Services\Rules::gradeEditable(auth()->user(), $a))
        <tr><td class="mono" data-label="Tanggal">{{ $a->tanggal_kegiatan }}</td><td class="card-title"><b>{{ $a->nama_kegiatan }}</b>@unless($canDel) <span class="badge" title="Lebih dari 7 hari sejak diinput">🔒 terkunci</span>@endunless</td><td data-label="Skala">{{ $a->tipe_skala }}</td>
            <td class="c mono" data-label="Siswa bernilai">{{ count($v) }}</td><td class="c mono" data-label="Rata-rata"><b>{{ $a->tipe_skala === 'angka' && $nums ? number_format(array_sum($nums) / count($nums), 1) : '-' }}</b></td>
            <td class="r"><div class="row row-end">
                @php($kosong = $students->count() - count(array_intersect_key($v, $students->keyBy('id')->all())))
                @if($canDel)<a class="btn btn-sm" href="{{ $url(['tab' => null, 'act' => $a->id]) }}">Edit</a>
                @elseif($kosong > 0)<a class="btn btn-sm" href="{{ $url(['tab' => null, 'act' => $a->id]) }}">Nilai Susulan ({{ $kosong }})</a>@endif
                @if($canDel)<form method="post" action="{{ route('grades.destroy', $a->id) }}" class="inline" data-confirm="Hapus kegiatan &quot;{{ $a->nama_kegiatan }}&quot; beserta seluruh nilainya?">@csrf<button class="btn btn-sm btn-danger">Hapus</button></form>
                @endif
            </div></td></tr>
    @endforeach
    </tbody></table></div>@endif
</div>

@else
@php($ordered = $acts->reverse()->values())
<div class="card card-tight">
    <div class="row" style="padding:14px 16px;justify-content:space-between;border-bottom:1px solid var(--line)"><h2>Rekap Nilai</h2>
        @if($classId && $subjectId)<div class="row"><a class="btn btn-sm" href="{{ route('export.grades', ['class' => $classId, 'subject' => $subjectId]) }}">Unduh Excel</a>
        <a class="btn btn-sm" target="_blank" href="{{ route('letters.report', ['class' => $classId, 'subject' => $subjectId]) }}">Laporan Cetak</a></div>@endif</div>
    @if($acts->isEmpty())<div class="empty">Belum ada data nilai.</div>@else
    <div class="scroll"><table class="tbl mx"><thead><tr><th>Nama</th>@foreach($ordered as $a)<th class="c" title="{{ $a->tanggal_kegiatan }}">{{ $a->nama_kegiatan }}</th>@endforeach<th class="c">Rata-rata (angka)</th></tr></thead><tbody>
    @foreach($students as $s)
        @php($nums = [])
        <tr><td><b>{{ $s->nama }}</b><br><small class="mono">{{ $s->nis }}</small></td>
        @foreach($ordered as $a)
            @php($v = $vals[$a->id][$s->id] ?? '-')
            @php($a->tipe_skala === 'angka' && is_numeric($v) ? $nums[] = (float) $v : null)
            <td @class(['g', 'low' => (is_numeric($v) && (float) $v < 70) || in_array($v, ['D', 'E'], true)])>{{ $v }}</td>
        @endforeach
        <td class="g"><b>{{ $nums ? number_format(array_sum($nums) / count($nums), 1) : '-' }}</b></td></tr>
    @endforeach
    </tbody></table></div>@endif
</div>
@endif
@endsection
