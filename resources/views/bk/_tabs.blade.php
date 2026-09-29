@php($bkTabs = ['bk.home' => ['Ringkasan', null]] + (auth()->user()->can('bk') ? ['bk' => ['Presensi BK', null]] : []))
<div class="seg seg-scroll" role="navigation" aria-label="Bagian BK" style="margin-bottom:14px">
    @foreach($bkTabs as $r => [$l])
        <a href="{{ route($r) }}" @class(['on' => request()->routeIs($r)])>{{ $l }}</a>
    @endforeach
    @foreach(\App\Support\BkModules::all() as $j => $mod)
        <a href="{{ route('bk.records', $j) }}" @class(['on' => request()->routeIs('bk.records') && request()->route('jenis') === $j])>{{ $mod['icon'] }} {{ $mod['label'] }}</a>
    @endforeach
</div>
