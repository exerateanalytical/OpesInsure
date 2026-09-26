{{-- Marketplace hub ("/insurance"). Design source: screens/marketplace_hub/01_insurance_marketplace_hub.png --}}
@extends('public.layout')
@section('title', __('desk.market.h1').' — OpesInsure')
@section('description', __('desk.market.lede'))
@section('content')
<section class="mk-hero" aria-labelledby="mk-title">
  <img class="mk-pat" src="/landing/img/desk/pattern.webp" alt="" aria-hidden="true">
  <div class="wrap-x mk-grid">
    <div class="mk-copy">
      <p class="d-eyebrow">{{ __('desk.market.eyebrow') }}</p>
      <h1 id="mk-title">{{ __('desk.market.h1') }}</h1>
      <p class="lede">{{ __('desk.market.lede') }}</p>
      <form class="mk-search" action="/insurance" method="get" role="search">
        <label class="sr-only" for="mk-q">{{ __('desk.market.search_ph') }}</label>
        <span class="in">@include('public.partials.i', ['n' => 'search'])<input id="mk-q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('desk.market.search_ph') }}" maxlength="80"></span>
        @if($cat)<input type="hidden" name="cat" value="{{ $cat }}">@endif
        <button class="dbtn dbtn-primary" type="submit">{{ __('desk.market.search_btn') }}</button>
      </form>
    </div>
    <div class="mk-art">
      <picture><source type="image/webp" srcset="/landing/img/desk/hero-market.webp"><img src="/landing/img/desk/hero-market.jpg" alt="" width="315" height="226" fetchpriority="high"></picture>
      <div class="mk-promo"><b>{{ __('desk.market.promo_t') }}</b><span>{{ __('desk.market.promo_d') }}</span></div>
    </div>
    <ul class="mk-trust">
      @foreach(__('desk.market.trust') as $i => [$t, $d])
        <li><span class="gold-ic">@include('public.partials.i', ['n' => ['shield', 'doc', 'lock'][$i]])</span><span><b>{{ $t }}</b><small>{{ $d }}</small></span></li>
      @endforeach
    </ul>
  </div>
</section>
@include('public.partials.catalogue', ['variant' => 'market'])
@endsection
