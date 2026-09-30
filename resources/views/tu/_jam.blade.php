@if($cur && ($cur->jam_masuk ?? null))&nbsp;· masuk {{ $cur->jam_masuk }}@if($cur->jam_pulang) · pulang {{ $cur->jam_pulang }}@endif @if($cur->terlambat) · <span class="warn">terlambat</span>@endif @if($cur->jarak !== null) · {{ $cur->jarak }} m @endif @endif
@if($cur && ($cur->sumber ?? "tu") === "mandiri")&nbsp;· 📱 mandiri @endif
