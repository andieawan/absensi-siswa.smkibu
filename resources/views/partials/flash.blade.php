@foreach(['success' => 'success', 'warning' => 'warn', 'error' => 'error', 'info' => 'info'] as $key => $cls)
    @if(session($key))<div class="alert alert-{{ $cls }}" role="alert">{{ session($key) }}</div>@endif
@endforeach
@if($errors->any())
    <div class="alert alert-error" role="alert">
        @if($errors->count() === 1){{ $errors->first() }}@else<b>Periksa kembali isian:</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    </div>
@endif
