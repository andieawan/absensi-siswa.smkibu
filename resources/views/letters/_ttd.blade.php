<p style="text-align:right;margin-top:28px">{{ ($place ?? '') !== '' ? $place : '........................' }}, {{ \App\Support\Dates::long() }}</p>
<div class="ttd">
    <div>Mengetahui,<br>Kepala Sekolah,<div class="sp"></div><b><u>{{ $settings->kepsek_nama ?: '(........................)' }}</u></b><br>NIP. ....................</div>
    <div><br>{{ $bkLabel }},<div class="sp"></div><b><u>{{ $settings->bk_nama ?: '(........................)' }}</u></b><br>NIP. ....................</div>
</div>
