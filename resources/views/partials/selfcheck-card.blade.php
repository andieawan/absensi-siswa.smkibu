{{-- Kartu "Absen Hari Ini" untuk guru & staf. Menampilkan dirinya sendiri hanya bila fitur aktif untuk pengguna ini. --}}
@php
    $sc = \App\Services\StaffService::state(auth()->user());
@endphp
@if($sc)
@php
    $row = $sc['row']; $cfg = $sc['cfg']; $geo = $cfg['radius'] > 0;
    $lbl = \App\Services\StaffService::STATUS;
@endphp
<section class="card selfcheck" aria-labelledby="sc-h">
    <div class="sc-top">
        <div><h2 id="sc-h" style="margin:0">Absen Hari Ini</h2><small>{{ \App\Support\Dates::long($sc['today']) }} · pukul {{ $sc['now'] }} WIB</small></div>
        @if($row)
            <span @class(['badge', 'b-ok' => $row->status === 'H' && ! $row->terlambat, 'b-warn' => ($row->status === 'H' && $row->terlambat) || in_array($row->status, ['I', 'S', 'D']), 'b-bad' => $row->status === 'A'])>{{ $lbl[$row->status] }}@if($row->terlambat) · Terlambat @endif</span>
        @else
            <span class="badge">Belum absen</span>
        @endif
    </div>

    @if($row && $row->status === 'H' && $row->jam_masuk)
        <p class="sc-times"><span>Masuk <b class="mono">{{ $row->jam_masuk }}</b></span><span>Pulang <b class="mono">{{ $row->jam_pulang ?: '—' }}</b></span></p>
    @elseif($row && $row->status !== 'H')
        <p class="mut" style="margin:8px 0 0">{{ $row->notes ?: 'Tercatat oleh '.($row->sumber === 'mandiri' ? 'Anda' : 'TU').'.' }}</p>
    @endif

    @if(! $row || ($row->status === 'H' && ! $row->jam_masuk) || ($row->status === 'H' && $row->jam_masuk && ! $row->jam_pulang) || ($row->sumber === 'mandiri' && $row->status !== 'H' && ! $row->jam_masuk))
    <form method="post" action="{{ route('selfcheck.store') }}" data-geo="{{ $geo ? 1 : 0 }}" class="sc-form">@csrf
        <input type="hidden" name="aksi" value=""><input type="hidden" name="lat" value=""><input type="hidden" name="lng" value="">
        @if(! $row || ($row->status === 'H' && ! $row->jam_masuk))
            <button class="btn btn-pri sc-big" name="aksi" value="masuk" data-busy="{{ $geo ? 'Memeriksa lokasi…' : 'Mencatat…' }}">✅ Absen Masuk</button>
        @elseif($row->status === 'H' && ! $row->jam_pulang)
            <button class="btn btn-dark sc-big" name="aksi" value="pulang" data-busy="{{ $geo ? 'Memeriksa lokasi…' : 'Mencatat…' }}">🏠 Absen Pulang</button>
        @endif
    </form>
    @endif

    @if(! $row || ($row->sumber === 'mandiri' && $row->status !== 'H' && ! $row->jam_masuk))
    <details class="more" style="margin-top:10px"><summary>Tidak masuk hari ini? Lapor Izin / Sakit / Dinas Luar</summary>
        <form method="post" action="{{ route('selfcheck.store') }}" class="fields" style="margin-top:8px">@csrf <input type="hidden" name="aksi" value="lapor">
            <div><label for="sc-st">Keterangan</label><select id="sc-st" name="status"><option value="I">Izin</option><option value="S">Sakit</option><option value="D">Dinas Luar</option></select></div>
            <div style="grid-column:span 2"><label for="sc-note">Alasan singkat</label><input id="sc-note" type="text" name="catatan" maxlength="200" placeholder="mis. keperluan keluarga / surat dokter / tugas dinas" required></div>
            <div style="align-self:end"><button class="btn">Kirim Laporan</button></div>
        </form>
    </details>
    @endif

    <small class="mut" style="display:block;margin-top:10px">
        Batas jam masuk <b>{{ $cfg['batas'] }}</b>.
        @if($geo)📍 Lokasi Anda diperiksa (harus dalam radius {{ $cfg['radius'] }} m dari sekolah) — izinkan akses lokasi saat diminta.@endif
        @if($row && $row->jarak !== null) Jarak saat masuk: {{ $row->jarak }} m.@endif
    </small>
</section>
@endif
