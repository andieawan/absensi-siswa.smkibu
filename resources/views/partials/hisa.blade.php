{{-- Baris siswa: tombol H/I/S/A + catatan (catatan dilipat bila kosong). Butuh: $s, $i, $cur (Attendance|null) --}}
@php($st = old("status.{$s->id}", $cur->status ?? 'H'))
@php($note = old("notes.{$s->id}", $cur->notes ?? ''))
<div class="stu stu-att" data-row>
    <span class="no mono">{{ $i + 1 }}</span>
    <div class="nm"><b>{{ $s->nama }}</b><small>{{ $s->nis }} · {{ $s->jk }}</small></div>
    <div class="hisa" role="radiogroup" aria-label="Status kehadiran {{ $s->nama }}">
        @foreach(\App\Models\Attendance::STATUS as $k => $lbl)
            <label title="{{ $lbl }}"><input type="radio" name="status[{{ $s->id }}]" value="{{ $k }}" data-status="{{ $k }}" @checked($st === $k) aria-label="{{ $lbl }}"><span class="{{ $k }}" aria-hidden="true">{{ $k }}</span></label>
        @endforeach
    </div>
    <button type="button" @class(['note-toggle', 'has-note' => $note !== '']) data-note-toggle aria-expanded="{{ $note !== '' ? 'true' : 'false' }}" aria-controls="note-{{ $s->id }}" title="Catatan">✎<span class="sr">Catatan untuk {{ $s->nama }}</span></button>
    <input id="note-{{ $s->id }}" @class(['note', 'note-open' => $note !== '']) type="text" name="notes[{{ $s->id }}]" value="{{ $note }}" placeholder="Catatan, mis. surat dokter / izin acara keluarga" maxlength="200" aria-label="Catatan {{ $s->nama }}">
</div>
