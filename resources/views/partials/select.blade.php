{{-- @include('partials.select', ['name'=>..., 'options'=>[val=>label], 'selected'=>..., 'attrs'=>'']) --}}
<select name="{{ $name }}" @isset($id) id="{{ $id }}" @endisset {!! $attrs ?? '' !!}>
    @foreach($options as $val => $label)
        <option value="{{ $val }}" @selected((string) $val === (string) ($selected ?? ''))>{{ $label }}</option>
    @endforeach
</select>
