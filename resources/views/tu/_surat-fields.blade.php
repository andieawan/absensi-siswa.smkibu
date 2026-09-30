{{-- Kolom form register surat. $arah, $rec (TuSurat|null), $px, $today --}}
@php($v = fn (string $k, $d = '') => $rec ? ($rec->{$k} ?? $d) : old($k, $d))
<div class="fields">
    <div><label for="{{ $px }}tgl">{{ $arah === 'masuk' ? 'Tanggal diterima' : 'Tanggal surat' }}</label><input id="{{ $px }}tgl" type="date" name="tanggal" value="{{ $v('tanggal', $today) }}" required></div>
    <div><label for="{{ $px }}no">{{ $arah === 'masuk' ? 'Nomor surat (dari pengirim)' : 'Nomor surat' }} @if($arah === 'keluar')<small>(kosongkan = otomatis)</small>@endif</label><input id="{{ $px }}no" type="text" name="nomor" value="{{ $rec ? $rec->nomor : old('nomor') }}" maxlength="80"></div>
    <div><label for="{{ $px }}ph">{{ $arah === 'masuk' ? 'Pengirim' : 'Tujuan' }}</label><input id="{{ $px }}ph" type="text" name="pihak" value="{{ $v('pihak') }}" maxlength="191" required></div>
    @if($arah === 'masuk')
        <div><label for="{{ $px }}st">Status</label><select id="{{ $px }}st" name="status">@foreach(\App\Services\TuService::STATUS_MASUK as $o)<option @selected($v('status', 'Baru') === $o)>{{ $o }}</option>@endforeach</select></div>
    @endif
</div>
<div class="field" style="margin-top:12px"><label for="{{ $px }}pf">Perihal</label><input id="{{ $px }}pf" type="text" name="perihal" value="{{ $v('perihal') }}" maxlength="191" required></div>
<div class="field"><label for="{{ $px }}is">{{ $arah === 'masuk' ? 'Ringkasan isi' : 'Isi surat' }} <small>(opsional{{ $arah === 'keluar' ? '; dipakai saat surat dicetak' : '' }})</small></label><textarea id="{{ $px }}is" name="isi" rows="3">{{ $v('isi') }}</textarea></div>
@if($arah === 'masuk')
    <div class="field"><label for="{{ $px }}ds">Disposisi <small>(opsional)</small></label><input id="{{ $px }}ds" type="text" name="disposisi" value="{{ $v('disposisi') }}" maxlength="191" placeholder="mis. Kepsek → Waka Kurikulum"></div>
@endif
<div class="field"><label for="{{ $px }}bk">Scan / foto surat <small>(opsional, JPG/PNG/PDF maks. 8 MB)</small></label>
    <div class="row"><input id="{{ $px }}bk" type="file" name="berkas" accept="image/*,application/pdf" style="flex:1;min-width:180px"><button type="button" class="btn btn-sm" data-camera="#{{ $px }}bk">📷 Foto dengan kamera</button></div>
    @if($rec?->berkas_path)<small class="mut">Sudah ada berkas — pilih berkas baru untuk mengganti.</small>@endif</div>
