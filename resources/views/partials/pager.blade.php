@if($p->hasPages())
<nav class="pager" aria-label="Halaman">
    @if(! $p->onFirstPage())<a href="{{ $p->previousPageUrl() }}">‹ Sebelumnya</a>@endif
    <span class="on">{{ $p->currentPage() }} / {{ $p->lastPage() }}</span>
    @if($p->hasMorePages())<a href="{{ $p->nextPageUrl() }}">Berikutnya ›</a>@endif
    <span class="mut">{{ $p->total() }} data</span>
</nav>
@endif
