<div class="row" style="gap:4px 14px">
    @foreach($items as $val => $label)
        <label class="chk" style="font-size:12px"><input type="checkbox" name="{{ $name }}[]" value="{{ $val }}" @checked(in_array($val, $sel ?? [], false))> {{ $label }}</label>
    @endforeach
</div>
