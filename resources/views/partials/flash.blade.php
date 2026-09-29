@php($icons = ['success' => '✓', 'warning' => '!', 'error' => '✕', 'info' => 'i'])
@foreach(['success' => 'success', 'warning' => 'warn', 'error' => 'error', 'info' => 'info'] as $key => $cls)
    @if(session($key))
        <div class="alert alert-{{ $cls }} alert-flash" role="{{ $key === 'error' ? 'alert' : 'status' }}">
            <span class="alert-ic" aria-hidden="true">{{ $icons[$key] }}</span><span class="alert-txt">{{ session($key) }}</span>
            <button type="button" class="alert-x" data-dismiss aria-label="Tutup pesan">×</button>
        </div>
    @endif
@endforeach
@if(isset($errors) && $errors->any())
    <div class="alert alert-error alert-flash" role="alert">
        <span class="alert-ic" aria-hidden="true">✕</span>
        <span class="alert-txt">@if($errors->count() === 1){{ $errors->first() }}@else<b>Periksa kembali isian:</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif</span>
        <button type="button" class="alert-x" data-dismiss aria-label="Tutup pesan">×</button>
    </div>
@endif
