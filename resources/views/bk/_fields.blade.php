{{-- Kolom form catatan BK. $m = definisi modul, $rec = catatan (null saat tambah), $px = prefiks id unik --}}
@php
    $v = fn (string $k, $def = '') => $rec ? ($rec->{$k} ?? $def) : old($k, $def);
    $xv = fn (string $k) => $rec ? $rec->x($k) : old('extra.'.$k, '');
@endphp
<div class="fields">
    <div><label for="{{ $px }}tgl">Tanggal</label><input id="{{ $px }}tgl" type="date" name="tanggal" value="{{ $v('tanggal', $today) }}" max="{{ $today }}" required></div>
    @if($m['kategori'])
        <div><label for="{{ $px }}kat">{{ $m['kategori'][0] }}</label><select id="{{ $px }}kat" name="kategori" required>
            @unless($rec)<option value="">— pilih —</option>@endunless
            @foreach($m['kategori'][1] as $o)<option @selected($v('kategori') === $o)>{{ $o }}</option>@endforeach</select></div>
    @endif
    @foreach($m['extra'] as $k => [$label, $type, $opt])
        <div><label for="{{ $px }}x{{ $k }}">{{ $label }}</label>
        @if($type === 'select')
            <select id="{{ $px }}x{{ $k }}" name="extra[{{ $k }}]"><option value="">—</option>@foreach($opt as $o)<option @selected($xv($k) === $o)>{{ $o }}</option>@endforeach</select>
        @else
            <input id="{{ $px }}x{{ $k }}" type="text" name="extra[{{ $k }}]" value="{{ $xv($k) }}" placeholder="{{ $opt }}" maxlength="191">
        @endif</div>
    @endforeach
    @if($m['status'])
        <div><label for="{{ $px }}st">Status penanganan</label><select id="{{ $px }}st" name="status">@foreach(\App\Support\BkModules::STATUS as $o)<option @selected($v('status', 'Proses') === $o)>{{ $o }}</option>@endforeach</select></div>
    @endif
</div>
<div class="field" style="margin-top:12px"><label for="{{ $px }}jd">{{ $m['judul'][0] }}</label><input id="{{ $px }}jd" type="text" name="judul" value="{{ $v('judul') }}" placeholder="{{ $m['judul'][1] }}" maxlength="191" required></div>
<div class="field"><label for="{{ $px }}ur">{{ $m['uraian'] }} <small>(opsional)</small></label><textarea id="{{ $px }}ur" name="uraian" rows="3">{{ $v('uraian') }}</textarea></div>
@if($m['tindak'])
    <div class="field"><label for="{{ $px }}tl">{{ $m['tindak'] }} <small>(opsional)</small></label><textarea id="{{ $px }}tl" name="tindak_lanjut" rows="2">{{ $v('tindak_lanjut') }}</textarea></div>
@endif
@if($m['rahasia'])
    <label class="chk"><input type="checkbox" name="rahasia" value="1" @checked($rec ? $rec->rahasia : old('rahasia'))> 🔒 <b>Sangat Rahasia</b> <small class="mut">— Wali Kelas &amp; Kepala Sekolah hanya melihat bahwa ada kasus, tanpa detail</small></label>
@endif
@if($m['berkas'])
    <div class="field"><label for="{{ $px }}bk">Scan / foto surat bertanda tangan <small>(opsional, JPG/PNG/PDF maks. 8 MB)</small></label>
        <div class="row"><input id="{{ $px }}bk" type="file" name="berkas" accept="image/*,application/pdf" style="flex:1;min-width:180px">
            <button type="button" class="btn btn-sm" data-camera="#{{ $px }}bk">📷 Foto dengan kamera</button></div>
        @if($rec?->berkas_path)<small class="mut">Sudah ada berkas — pilih berkas baru untuk mengganti.</small>@endif
    </div>
@endif
