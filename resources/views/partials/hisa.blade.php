{{-- Baris siswa dengan tombol H/I/S/A + catatan. Butuh: $s, $i, $cur (Attendance|null) --}}
@php($st = old("status.{$s->id}", $cur->status ?? 'H'))
<div class="stu">
    <span class="no mono">{{ $i + 1 }}</span>
    <div class="nm"><b>{{ $s->nama }}</b><small>{{ $s->nis }} · {{ $s->jk }}</small></div>
    <div class="hisa" role="radiogroup" aria-label="Status {{ $s->nama }}">
        @foreach(\App\Models\Attendance::STATUS as $k => $lbl)
            <label title="{{ $lbl }}"><input type="radio" name="status[{{ $s->id }}]" value="{{ $k }}" data-status="{{ $k }}" @checked($st === $k)><span class="{{ $k }}">{{ $k }}</span></label>
        @endforeach
    </div>
    <input class="note" type="text" name="notes[{{ $s->id }}]" value="{{ old("notes.{$s->id}", $cur->notes ?? '') }}" placeholder="Catatan (opsional)" maxlength="200" aria-label="Catatan {{ $s->nama }}">
</div>
