@php($ls = $student ?? null)
<details class="more" data-close-outside style="position:relative"><summary class="btn btn-sm">🖨 Cetak Surat ▾</summary>
    <div class="card" style="position:absolute;right:0;z-index:20;min-width:270px;padding:6px;margin-top:4px;display:grid;gap:2px;text-align:left">
        <a href="{{ route('letters.summons', $ls) }}" target="_blank">Surat Panggilan Wali Murid</a>
        <a href="{{ route('letters.warning', $ls) }}" target="_blank">Surat Peringatan</a>
        <a href="{{ route('letters.form', ['teguran', $ls]) }}" target="_blank">Surat Teguran Tertulis</a>
        <a href="{{ route('letters.form', ['pernyataan-berhenti', $ls]) }}" target="_blank">Pernyataan Siap Diberhentikan</a>
        <a href="{{ route('letters.form', ['pernyataan-mundur', $ls]) }}" target="_blank">Pernyataan Mengundurkan Diri</a>
        <a href="{{ route('letters.form', ['berita-acara', $ls]) }}" target="_blank">Berita Acara Pemanggilan Ortu</a>
        <a href="{{ route('letters.form', ['izin', $ls]) }}" target="_blank">Surat Izin Meninggalkan Sekolah</a>
    </div>
</details>
