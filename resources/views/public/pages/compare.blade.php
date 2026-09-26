{{-- Compare ("/compare?p[]=CODE"). Design source: screens/compare_buy_flow/01_compare_directory_or_compare_page.png + 02_compare_stage.png --}}
@extends('public.layout')
@php
  $M = \App\Interfaces\Http\Controllers\Web\PublicMarketplace::class;
  $codes = array_column($picked, 'code');
  $lineName = __('desk.lines.'.$slug)[0];
  $url = fn (array $c, ?string $v = null) => '/compare?'.http_build_query(array_filter(['p' => array_values($c) ?: null, 'line' => $c ? null : $slug, 'view' => ($v ?? $view) === 'table' ? 'table' : null]));
  $priced = array_filter($picked, fn ($p) => $p['premium']);
  $best = $priced ? collect($priced)->sortBy('premium')->first()['code'] : null;
@endphp
@section('title', __('desk.compare.title').' — OpesInsure')
@section('description', __('desk.compare.lede'))
@section('content')
<div class="cmp-page">
  <div class="tribal-side" aria-hidden="true"></div>
  <section class="cmp-hero" aria-labelledby="cmp-title">
    <picture class="cmp-banner"><source type="image/webp" srcset="/landing/img/desk/banner.webp"><img src="/landing/img/desk/banner.webp" alt="" width="1400" height="788" fetchpriority="high"></picture>
    <div class="wrap-x">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/">{{ __('desk.home') }}</a>@include('public.partials.i', ['n' => 'chev'])<span aria-current="page">{{ __('desk.compare.title') }}</span></nav>
      <h1 id="cmp-title">{{ __('desk.compare.title') }}</h1>
      <p class="lede">{{ __('desk.compare.lede') }}</p>
      <ul class="cmp-trust">
        @foreach(__('desk.compare.trust') as $i => [$t, $d])
          <li>@include('public.partials.i', ['n' => ['shield', 'headset', 'star'][$i]])<span><b>{{ $t }}</b><small>{{ $d }}</small></span></li>
        @endforeach
      </ul>
    </div>
  </section>

  <div class="wrap-x cmp-grid">
    <aside class="panel rail">
      <div class="rail-head"><h2>{{ __('desk.compare.your_search') }}</h2></div>
      <label class="field-s"><span>{{ __('desk.compare.category') }}</span>
        <select onchange="location.href='/compare?line='+this.value" aria-label="{{ __('desk.compare.category') }}">
          @foreach(array_keys($M::LINES) as $l)<option value="{{ $l }}" @selected($l === $slug)>{{ __('desk.lines.'.$l)[0] }} ({{ $counts[$l] }})</option>@endforeach
        </select>
      </label>
      <h3 class="rail-sub">{{ __('desk.compare.products') }}</h3>
      <ul class="pick">
        @forelse($candidates as $cand)
          @php $in = in_array($cand['code'], $codes, true); @endphp
          <li>
            <span><b>{{ $cand['name'] }}</b><small>{{ $cand['carrier'] }} · {{ $cand['premium'] ? $M::money($cand['premium']) : __('desk.list.on_request') }}</small></span>
            @if($in)
              <a class="pill on" href="{{ $url(array_diff($codes, [$cand['code']])) }}" aria-label="{{ __('desk.compare.remove') }} {{ $cand['name'] }}">@include('public.partials.i', ['n' => 'check']){{ __('desk.compare.added') }}</a>
            @elseif(count($codes) < 4)
              <a class="pill" href="{{ $url([...$codes, $cand['code']]) }}">+ {{ __('desk.compare.add') }}</a>
            @else
              <span class="pill off" title="{{ __('desk.compare.full') }}">{{ __('desk.compare.full') }}</span>
            @endif
          </li>
        @empty
          <li class="muted-s">{{ __('desk.list.empty_all') }}</li>
        @endforelse
      </ul>
    </aside>

    <section class="cmp-main" aria-labelledby="q-title">
      <ol class="stepbar">
        @foreach(__('desk.compare.steps') as $i => [$t, $d])
          <li class="{{ $i < 2 || ($i === 2 && count($picked)) ? 'done' : '' }} {{ $i === 2 ? 'cur' : '' }}"><span class="n">{{ $i + 1 }}</span><span><b>{{ $t }}</b><small>{{ __($d, ['line' => $lineName, 'n' => count($picked)]) }}</small></span></li>
        @endforeach
      </ol>

      <div class="q-head">
        <div><h2 id="q-title">{{ __('desk.compare.n_quotes', ['n' => count($picked)]) }}</h2><p>{{ __('desk.compare.sub') }}</p></div>
        @if($picked)
          <div class="seg" role="group" aria-label="{{ __('desk.compare.view') }}"><span>{{ __('desk.compare.view') }}</span>
            <a href="{{ $url($codes, 'list') }}" @if($view === 'list') aria-current="true" @endif>@include('public.partials.i', ['n' => 'list']){{ __('desk.compare.list') }}</a>
            <a href="{{ $url($codes, 'table') }}" @if($view === 'table') aria-current="true" @endif>@include('public.partials.i', ['n' => 'grid']){{ __('desk.compare.table') }}</a>
          </div>
        @endif
      </div>

      @if(!$picked)
        <div class="panel empty-cmp">@include('public.partials.i', ['n' => 'scale'])<p>{{ __('desk.compare.none') }}</p><a class="dbtn dbtn-primary" href="/insurance/{{ $slug }}">{{ $lineName }} @include('public.partials.i', ['n' => 'arrow'])</a></div>
      @elseif($view === 'list')
        <ul class="qlist">
          @foreach($picked as $p)
            <li class="qcard {{ $p['code'] === $best ? 'best' : '' }}">
              <div class="q-prov">@include('public.partials.pmark', ['p' => $p])@if($p['code'] === $best)<span class="badge-best">@include('public.partials.i', ['n' => 'star']){{ __('desk.compare.best') }}</span>@endif</div>
              <div class="q-body">
                <h3>{{ $p['name'] }}</h3>
                <p>{{ __('desk.tab.'.$p['slug']) }} · {{ $p['carrier_full'] }}</p>
                <ul class="q-covers">@foreach($p['covers'] as $cand)<li>@include('public.partials.i', ['n' => $cand['optional'] ? 'star' : 'shield']){{ $cand['name'] }}@if($cand['optional']) <em>({{ __('desk.list.optional') }})</em>@endif</li>@endforeach</ul>
              </div>
              <div class="q-price">
                @if($p['premium'])<b>{{ $M::money($p['premium']) }}</b><small>{{ __('desk.list.per_year') }}</small>@else<b class="blue">{{ __('desk.list.on_request') }}</b>@endif
                @if($p['cover_max'])<span>@include('public.partials.i', ['n' => 'check']){{ __('desk.list.cover_upto') }} {{ $M::money($p['cover_max']) }}</span>@endif
                @if($p['deductible'])<span>@include('public.partials.i', ['n' => 'check']){{ __('desk.list.deductible') }} {{ $M::money($p['deductible']) }}</span>@endif
              </div>
              <div class="q-act">
                <a class="dbtn dbtn-navy" href="{{ url('/app/compare?product='.urlencode($p['code'])) }}">{{ __('desk.compare.select') }} @include('public.partials.i', ['n' => 'arrow'])</a>
                <a class="linkx" href="{{ $url(array_diff($codes, [$p['code']])) }}">@include('public.partials.i', ['n' => 'x']){{ __('desk.compare.remove') }}</a>
              </div>
            </li>
          @endforeach
        </ul>
      @else
        <div class="ctable-wrap panel">
          <table class="ctable">
            <thead><tr><th scope="col"><span class="sr-only">{{ __('desk.compare.key') }}</span></th>
              @foreach($picked as $p)
                <th scope="col" class="{{ $p['code'] === $best ? 'best' : '' }}">
                  @if($p['code'] === $best)<span class="badge-best">@include('public.partials.i', ['n' => 'star']){{ __('desk.compare.best') }}</span>@endif
                  @include('public.partials.pmark', ['p' => $p])
                  <span class="ct-name">{{ $p['name'] }}</span>
                  @if($p['premium'])<b>{{ $M::money($p['premium']) }}</b><small>{{ __('desk.list.per_year') }}</small>@else<b class="blue">{{ __('desk.list.on_request') }}</b>@endif
                  <a class="dbtn dbtn-navy" href="{{ url('/app/compare?product='.urlencode($p['code'])) }}">{{ __('desk.compare.select') }} @include('public.partials.i', ['n' => 'arrow'])</a>
                  <a class="linkx" href="{{ $url(array_diff($codes, [$p['code']])) }}">{{ __('desk.compare.remove') }}</a>
                </th>
              @endforeach
            </tr></thead>
            <tbody>
              <tr class="grp"><th scope="rowgroup" colspan="{{ count($picked) + 1 }}">{{ __('desk.compare.key') }}</th></tr>
              @foreach($covers as $cv)
                <tr><th scope="row">{{ $cv['name'] }}</th>
                  @foreach($picked as $p)
                    @php $hit = collect($p['covers'])->firstWhere('code', $cv['code']); @endphp
                    <td>@if($hit)<span class="yes" title="{{ $hit['optional'] ? __('desk.list.optional') : '' }}">@include('public.partials.i', ['n' => 'check'])</span>@if($hit['optional'])<small>{{ __('desk.list.optional') }}</small>@endif @else<span class="no" aria-label="—">—</span>@endif</td>
                  @endforeach
                </tr>
              @endforeach
              <tr><th scope="row">{{ __('desk.list.cover_upto') }}</th>@foreach($picked as $p)<td>{{ $p['cover_max'] ? $M::money($p['cover_max']) : '—' }}</td>@endforeach</tr>
              <tr><th scope="row">{{ __('desk.compare.deductible') }}</th>@foreach($picked as $p)<td>{{ $p['deductible'] ? $M::money($p['deductible']) : '—' }}</td>@endforeach</tr>
              <tr><th scope="row">{{ __('desk.compare.premium') }}</th>@foreach($picked as $p)<td><b>{{ $p['premium'] ? $M::money($p['premium']) : __('desk.list.on_request') }}</b></td>@endforeach</tr>
            </tbody>
          </table>
        </div>
      @endif
      <p class="cmp-note">@include('public.partials.i', ['n' => 'help']){{ __('desk.compare.buy_note') }} {{ __('desk.list.note') }}</p>
    </section>
  </div>
</div>
@endsection
