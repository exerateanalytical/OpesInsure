@extends('public.layout')
@section('title', __('site.providers_page.title').' — OpesInsure')
@section('description', __('site.providers_page.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.providers_page.title'), 'lede' => __('site.providers_page.lede')])
<section style="padding-top:0"><div class="wrap">
  <form class="filters" id="provider-filters" action="/providers" method="get" role="search">
    <div class="field">
      <label for="f-q">{{ __('site.providers_page.search') }}</label>
      <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('site.providers_page.search_placeholder') }}" autocomplete="off">
    </div>
    <div class="field">
      <label for="f-type">{{ __('site.providers_page.type') }}</label>
      <select id="f-type" name="type">
        <option value="">{{ __('site.providers_page.type_all') }}</option>
        <option value="insurer" @selected($filters['type'] === 'insurer')>{{ __('site.providers_page.type_insurer') }}</option>
        <option value="broker" @selected($filters['type'] === 'broker')>{{ __('site.providers_page.type_broker') }}</option>
      </select>
    </div>
    <div class="field">
      <label for="f-branch">{{ __('site.providers_page.branch') }}</label>
      <select id="f-branch" name="branch">
        <option value="">{{ __('site.providers_page.branch_all') }}</option>
        <option value="IARD" @selected($filters['branch'] === 'IARD')>{{ __('site.providers_page.branch_iard') }}</option>
        <option value="LIFE" @selected($filters['branch'] === 'LIFE')>{{ __('site.providers_page.branch_life') }}</option>
      </select>
    </div>
    <div class="field">
      <label for="f-city">{{ __('site.providers_page.city') }}</label>
      <select id="f-city" name="city" @disabled(count($cities) === 0) aria-describedby="city-note">
        <option value="">{{ __('site.providers_page.city_all') }}</option>
        @foreach($cities as $c)<option value="{{ $c }}" @selected(mb_strtolower($filters['city']) === mb_strtolower($c))>{{ $c }}</option>@endforeach
      </select>
    </div>
    <button class="btn btn-gold" type="submit">{{ __('site.providers_page.apply') }}</button>
  </form>
  @if(count($cities) === 0)<p class="register-note" id="city-note">{{ __('site.providers_page.city_none') }}</p>@endif

  @if(count($entries) === 0)
    <div class="note" style="margin-top:26px">{{ __('site.providers_page.unavailable') }}</div>
  @else
    <div class="dir-meta">
      <span aria-live="polite">{!! __('site.providers_page.showing', ['count' => '<b id="provider-count">'.count($visible).'</b>']) !!}</span>
      <span>{{ __('site.providers_page.totals', ['insurers' => $stats['insurers'] ?? 0, 'brokers' => $stats['brokers'] ?? 0]) }} · {{ __('site.eco.source') }}</span>
    </div>
    <ul class="dir" id="provider-list">
      @foreach($entries as $p)
        <li data-kind="{{ $p['kind'] }}" data-branch="{{ $p['branch'] }}" data-city="{{ mb_strtolower((string) $p['city']) }}" data-search="{{ mb_strtolower($p['name'].' '.$p['short']) }}" @unless(isset($visible[$p['kind'].'|'.$p['name']])) hidden @endunless>
          @include('public.partials.wordmark', ['p' => $p, 'full' => true])
          @include('public.partials.provider-details', ['p' => $p])
        </li>
      @endforeach
    </ul>
    <p class="note" id="provider-empty" style="margin-top:18px" @if(count($visible)) hidden @endif>{{ __('site.providers_page.empty') }}</p>
  @endif
</div></section>
<section class="partner" aria-labelledby="partner-title">
  <div class="wrap row">
    <span class="hs">@include('public.partials.i', ['n' => 'handshake'])</span>
    <div><h2 id="partner-title">{{ __('site.partner.title') }}</h2><p>{{ __('site.partner.body') }}</p></div>
    <a class="btn btn-gold btn-sm" href="/partners">{{ __('site.partner.cta') }} @include('public.partials.i', ['n' => 'arrow'])</a>
  </div>
</section>
@endsection
