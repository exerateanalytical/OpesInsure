{{-- Canonical landing page ("/"). Design source: "landing page/landing page.png". --}}
@extends('public.layout')

@php
  $cats = ['motor', 'health', 'travel', 'home', 'business', 'life', 'accident'];
  $suffix = __('site.explore.suffix');
@endphp

@push('head')
<link rel="preload" as="image" type="image/webp" href="/landing/img/map-connected-600.webp" imagesrcset="/landing/img/map-connected-340.webp 340w, /landing/img/map-connected-600.webp 600w" imagesizes="(max-width: 640px) 70vw, 360px">
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'OpesInsure', 'url' => url('/'), 'logo' => url('/landing/img/favicon-512.png'), 'areaServed' => 'CM', 'slogan' => __('site.hero.h1').' '.__('site.hero.h1_accent')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
{{-- HERO --}}
<section class="lp-hero" aria-labelledby="hero-title">
  <div class="wrap grid-hero">
    <div>
      <p class="eyebrow">@foreach(__('site.hero.eyebrow') as $i => $w)@if($i)<span aria-hidden="true">•</span>@endif{{ $w }}@endforeach</p>
      <h1 id="hero-title">{{ __('site.hero.h1') }} <span class="accent">{{ __('site.hero.h1_accent') }}</span></h1>
      <p class="lede">{{ __('site.hero.lede') }}</p>
      <div class="btn-row">
        <a class="btn btn-gold" href="/insurance">{{ __('site.hero.cta_compare') }} @include('public.partials.i', ['n' => 'arrow'])</a>
        <a class="btn btn-outline" href="#explore">{{ __('site.hero.cta_covered') }}</a>
      </div>
      <ul class="trust">
        @foreach(['shield', 'lock', 'users', 'check'] as $i => $icon)
          <li>@include('public.partials.i', ['n' => $icon]){{ __('site.hero.trust')[$i] }}</li>
        @endforeach
      </ul>
    </div>
    <div class="hero-art">
      <div class="glow" aria-hidden="true"></div>
      <div class="ring" aria-hidden="true"></div>
      <div class="map-box">
        @include('public.partials.img', ['name' => 'map-connected', 'class' => 'map', 'alt' => __('site.hero.map_alt'), 'sizes' => '(max-width: 640px) 70vw, 380px', 'eager' => true, 'priority' => 'high'])
        @include('public.partials.img', ['name' => 'pin-cameroon', 'class' => 'pin', 'sizes' => '50px', 'eager' => true])
        <span class="pin-label">{{ __('site.hero.pin') }}</span>
      </div>
      @include('public.partials.img', ['name' => 'safer-brighter-africa', 'class' => 'script', 'alt' => __('site.hero.script_alt'), 'sizes' => '150px', 'eager' => true])
      @include('public.partials.phone-home')
      <p class="side-tag" aria-hidden="true">@foreach(__('site.hero.side') as $w){{ $w }}<br>@endforeach</p>
    </div>
  </div>
</section>

{{-- EXPLORE --}}
<section class="explore" id="explore" aria-labelledby="explore-title">
  <div class="wrap">
    <h2 class="section-title" id="explore-title">{{ __('site.explore.title') }}</h2>
    <p class="sub">{{ __('site.explore.sub') }}</p>
    <div class="cats">
      @foreach($cats as $key)
        <a class="cat" href="/insurance/{{ $key }}"><span class="badge">@include('public.partials.i', ['n' => $key])</span><span>{{ __('site.explore.cats.'.$key) }}@if($suffix)<br>{{ $suffix }}@endif</span></a>
      @endforeach
      <a class="cat all" href="/insurance"><span class="badge">@include('public.partials.i', ['n' => 'dots'])</span><span>{{ __('site.explore.all') }}</span></a>
    </div>
  </div>
</section>

{{-- PROVIDERS (official register, text wordmarks only) --}}
<section class="providers" id="providers" aria-labelledby="providers-title">
  <div class="wrap">
    <div class="section-head">
      <div><h2 class="section-title" id="providers-title">{{ __('site.providers.title') }}</h2><p class="sub">{{ __('site.providers.sub') }}</p></div>
      <a class="link" href="/providers">{{ __('site.providers.view_all') }} @include('public.partials.i', ['n' => 'arrow'])</a>
    </div>
    @if(count($featured))
      <ul class="logos" style="list-style:none;padding:0;margin:0">
        @foreach($featured as $p)<li>@include('public.partials.wordmark', ['p' => $p])</li>@endforeach
      </ul>
      <p class="register-note">{{ __('site.providers.register_note') }}</p>
    @else
      <p class="register-note"><a class="link" href="/providers">{{ __('site.providers.empty_home') }}</a></p>
    @endif
  </div>
</section>

{{-- HOW IT WORKS --}}
<section class="how" id="how" aria-labelledby="how-title">
  <div class="wrap">
    <h2 class="section-title" id="how-title">{{ __('site.how.title') }}</h2>
    <p class="sub" style="margin-inline:auto">{{ __('site.how.sub') }}</p>
    <ol class="steps">
      @foreach(__('site.how.steps') as $i => [$t, $d])
        <li class="step"><span class="n">{{ $i + 1 }}</span>@include('public.partials.i', ['n' => ['search', 'compare', 'card', 'doc'][$i]])<h3>{{ $t }}</h3><p>{{ $d }}</p></li>
      @endforeach
    </ol>
  </div>
</section>

{{-- APP --}}
<section class="pocket" id="app" aria-labelledby="app-title">
  @include('public.partials.img', ['name' => 'wave-light', 'class' => 'wave', 'sizes' => '100vw'])
  <div class="wrap grid2">
    <div class="art" aria-hidden="true">
      <div class="phone splash"><div class="screen">
        @include('public.partials.img', ['name' => 'app-icon', 'class' => 'big', 'sizes' => '96px'])
        <span class="brand">Opes<b>Insure</b></span>
        <p>{{ __('site.phone.slogan') }}</p>
        @include('public.partials.img', ['name' => 'gold-stroke', 'class' => 'stroke', 'sizes' => '150px'])
        <small>{{ mb_strtoupper(__('site.phone.safer')) }}</small>
      </div></div>
      <div class="phone list-phone"><div class="screen">
        <div class="notch"></div>
        <div class="list" style="padding-top:44px">
          <h5>{{ __('site.phone.my_policies') }}</h5>
          <div class="seg"><span class="on">{{ __('site.phone.active') }}</span><span>{{ __('site.phone.expired') }}</span></div>
          @foreach(__('site.phone.policies') as $i => [$t, $s])
            <div class="pol">@include('public.partials.i', ['n' => ['motor', 'health', 'home', 'travel'][$i]])<div><b>{{ $t }}</b><em>{{ __('site.phone.active') }}</em><small>{{ $s }}</small></div></div>
          @endforeach
        </div>
        <div class="tabs">@foreach(__('site.phone.tabs') as $i => $label)<span class="{{ $i === 1 ? 'on' : '' }}">@include('public.partials.i', ['n' => ['home', 'doc', 'shield', 'user'][$i]]){{ $label }}</span>@endforeach</div>
      </div></div>
    </div>
    <div style="position:relative">
      <h2 id="app-title">{!! nl2br(e(__('site.pocket.title'))) !!}</h2>
      <p class="sub">{{ __('site.pocket.sub') }}</p>
      <div class="feats">
        @foreach(__('site.pocket.feats') as $i => [$t, $d])
          <div class="feat"><span class="ico">@include('public.partials.i', ['n' => ['doc', 'refresh', 'shield'][$i]])</span><b>{{ $t }}</b><p>{{ $d }}</p></div>
        @endforeach
      </div>
      <div class="stores">
        <a class="store" href="/download">@include('public.partials.i', ['n' => 'play'])<span><small>{{ __('site.pocket.android_small') }}</small><strong>{{ __('site.pocket.android') }}</strong></span></a>
        <span class="store soon" aria-label="{{ __('site.pocket.ios_small') }} — {{ __('site.pocket.soon') }}">@include('public.partials.i', ['n' => 'apple'])<span><small>{{ __('site.pocket.ios_small') }}</small><strong>{{ __('site.pocket.ios') }}</strong></span><span class="chip warn">{{ __('site.pocket.soon') }}</span></span>
      </div>
      <div class="tagline">
        <span class="script-text">{{ __('site.pocket.script') }}</span>
        @include('public.partials.img', ['name' => 'gold-stroke', 'sizes' => '180px'])
      </div>
    </div>
  </div>
</section>

{{-- ECOSYSTEM: live counts from the official register only --}}
<section class="ecosystem" aria-labelledby="eco-title">
  <div class="wrap grid3">
    @include('public.partials.img', ['name' => 'map-dark', 'class' => 'map', 'sizes' => '260px'])
    <div><h2 id="eco-title">{{ __('site.eco.title') }}</h2>@if($stats['insurers'])<p style="color:#CFDCF0;font-size:13px;margin-top:10px">{{ __('site.eco.source') }}</p>@endif</div>
    <div class="stats">
      <div class="stat">@include('public.partials.i', ['n' => 'users'])
        @if($stats['insurers'])<b>{{ $stats['insurers'] }}</b><span>{{ __('site.eco.insurers') }}</span>@else<b class="text">{{ __('site.eco.insurers_fallback') }}</b>@endif
      </div>
      <div class="stat">@include('public.partials.i', ['n' => 'handshake'])
        @if($stats['brokers'])<b>{{ $stats['brokers'] }}</b><span>{{ __('site.eco.brokers') }}</span>@else<b class="text">{{ __('site.eco.brokers_fallback') }}</b>@endif
      </div>
      <div class="stat">@include('public.partials.i', ['n' => 'globe'])
        @if($stats['countries'])<b>{{ $stats['countries'] }}</b><span>{{ trans_choice('site.eco.countries', $stats['countries']) }}</span>@else<b class="text">{{ __('site.eco.countries_fallback') }}</b>@endif
      </div>
    </div>
  </div>
</section>

{{-- PARTNER CTA --}}
<section class="partner" aria-labelledby="partner-title">
  <div class="wrap row">
    <span class="hs">@include('public.partials.i', ['n' => 'handshake'])</span>
    <div><h2 id="partner-title">{{ __('site.partner.title') }}</h2><p>{{ __('site.partner.body') }}</p></div>
    <a class="btn btn-gold btn-sm" href="/partners">{{ __('site.partner.cta') }} @include('public.partials.i', ['n' => 'arrow'])</a>
  </div>
</section>

{{-- FINAL CTA --}}
<section class="final" aria-labelledby="final-title">
  @include('public.partials.img', ['name' => 'wave-gold', 'class' => 'wave', 'sizes' => '100vw'])
  <div class="wrap">
    <h2 id="final-title">{{ __('site.final.title') }}</h2>
    <p class="sub" style="margin-inline:auto">{{ __('site.final.sub') }}</p>
    <a class="btn btn-gold" href="/insurance">{{ __('site.final.cta') }} @include('public.partials.i', ['n' => 'arrow'])</a>
  </div>
</section>
@endsection
