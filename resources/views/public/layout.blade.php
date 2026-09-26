@php
  $locale = app()->getLocale();
  $title = trim($__env->yieldContent('title')) ?: __('site.meta_title');
  $description = trim($__env->yieldContent('description')) ?: __('site.meta_description');
  $canonical = url()->current();
  $nav = [
    ['/insurance', 'insurance', 'public.marketplace'],
    ['/providers', 'providers', 'public.providers'],
    ['/claims', 'claims', 'public.claims'],
    ['/how-it-works', 'how', 'public.how-it-works'],
    ['/about', 'about', 'public.about'],
  ];
  $current = request()->route()?->getName();
  $langUrl = fn (string $l) => request()->fullUrlWithQuery(['lang' => $l]);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $canonical }}">
<link rel="alternate" hreflang="en" href="{{ $canonical }}?lang=en">
<link rel="alternate" hreflang="fr" href="{{ $canonical }}?lang=fr">
<link rel="alternate" hreflang="x-default" href="{{ $canonical }}">
<meta name="theme-color" content="#031C44">
<meta property="og:type" content="website">
<meta property="og:site_name" content="OpesInsure">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ url('/landing/img/og-1200x630.jpg') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:locale" content="{{ $locale === 'fr' ? 'fr_CM' : 'en_CM' }}">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/landing/img/favicon-32.png">
<link rel="apple-touch-icon" href="/landing/img/favicon-180.png">
<link rel="preload" href="/fonts/manrope/manrope-latin-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/landing/site.css?v={{ @filemtime(public_path('landing/site.css')) }}">
@stack('head')
</head>
<body>
<a class="skip" href="#main">{{ __('site.skip') }}</a>
@include('public.partials.icons')

<header class="site-header">
  <div class="wrap bar">
    <a class="brand" href="/" aria-label="OpesInsure — {{ __('site.common.back_home') }}">@include('public.partials.img', ['name' => 'app-icon', 'sizes' => '40px', 'eager' => true])<span>Opes<b>Insure</b></span></a>
    <nav class="main-nav" aria-label="{{ __('site.nav.main') }}">
      @foreach($nav as [$href, $key, $route])
        <a href="{{ $href }}" @if($route && $current === $route) aria-current="page" @endif>{{ __('site.nav.'.$key) }}</a>
      @endforeach
    </nav>
    <div class="head-actions">
      <button class="icon-btn search-toggle" type="button" aria-expanded="false" aria-controls="site-search" aria-label="{{ __('site.nav.search_label') }}">@include('public.partials.i', ['n' => 'search'])</button>
      <a class="btn btn-sm btn-outline-light" href="/login" data-account-link data-label-account="{{ __('site.nav.my_account') }}">{{ __('site.nav.sign_in') }}</a>
      <a class="btn btn-sm btn-gold" href="/insurance">{{ __('site.nav.compare') }} @include('public.partials.i', ['n' => 'arrow'])</a>
      <nav class="lang" aria-label="{{ __('site.nav.language') }}">
        <a href="{{ $langUrl('en') }}" hreflang="en" lang="en" @if($locale === 'en') aria-current="true" @endif>EN</a>
        <a href="{{ $langUrl('fr') }}" hreflang="fr" lang="fr" @if($locale === 'fr') aria-current="true" @endif>FR</a>
      </nav>
      <button class="icon-btn menu-btn" type="button" aria-expanded="false" aria-controls="mobile-nav" aria-label="{{ __('site.nav.menu') }}">@include('public.partials.i', ['n' => 'menu'])</button>
    </div>
  </div>
  <div class="search-panel" id="site-search" hidden>
    <form class="wrap" action="/providers" method="get" role="search">
      <label class="sr-only" for="site-search-q">{{ __('site.nav.search_label') }}</label>
      <input id="site-search-q" type="search" name="q" placeholder="{{ __('site.nav.search_placeholder') }}" autocomplete="off">
      <button class="btn btn-gold btn-sm" type="submit">{{ __('site.nav.search_go') }}</button>
    </form>
  </div>
  <nav class="mobile-nav" id="mobile-nav" aria-label="{{ __('site.nav.main') }}" hidden>
    <div class="wrap">
      <ul>
        @foreach($nav as [$href, $key, $route])
          <li><a href="{{ $href }}">{{ __('site.nav.'.$key) }}</a></li>
        @endforeach
        <li><a href="/providers">{{ __('site.nav.search') }}</a></li>
      </ul>
      <div class="btn-row">
        <a class="btn btn-sm btn-gold" href="/insurance">{{ __('site.nav.compare') }}</a>
        <a class="btn btn-sm btn-outline-light" href="/login" data-account-link data-label-account="{{ __('site.nav.my_account') }}">{{ __('site.nav.sign_in') }}</a>
      </div>
    </div>
  </nav>
</header>

<main id="main">
@yield('content')
</main>

<footer class="site-footer">
  <div class="wrap">
    <div class="cols">
      <div>
        <a class="brand on-light" href="/">@include('public.partials.img', ['name' => 'app-icon', 'sizes' => '40px'])<span>Opes<b>Insure</b></span></a>
        <p class="tag">{{ __('site.tagline') }}</p>
      </div>
      <div>
        <h2>{{ __('site.footer.insurance') }}</h2>
        <ul>
          @foreach(__('site.explore.cats') as $key => $label)
            <li><a href="/insurance/{{ $key }}">{{ $label }}</a></li>
          @endforeach
        </ul>
      </div>
      <div>
        <h2>{{ __('site.footer.company') }}</h2>
        <ul>
          <li><a href="/about">{{ __('site.footer.about') }}</a></li>
          <li><a href="/insurance">{{ __('desk.tab.all') }}</a></li>
          <li><a href="/compare">{{ __('desk.compare.title') }}</a></li>
          <li><a href="/providers">{{ __('site.footer.providers') }}</a></li>
          <li><a href="/partners">{{ __('site.footer.partner') }}</a></li>
          <li><a href="/how-it-works">{{ __('site.footer.how') }}</a></li>
          <li><a href="/admin/login">{{ __('site.footer.staff') }}</a></li>
        </ul>
      </div>
      <div>
        <h2>{{ __('site.footer.support') }}</h2>
        <ul>
          <li><a href="/faq">{{ __('site.footer.help') }}</a></li>
          <li><a href="/claims">{{ __('site.footer.claims') }}</a></li>
          <li><a href="/contact">{{ __('site.footer.contact') }}</a></li>
          <li><a href="/terms">{{ __('site.footer.terms') }}</a></li>
          <li><a href="/privacy">{{ __('site.footer.privacy') }}</a></li>
          <li><a href="/account/delete">{{ __('site.footer.delete') }}</a></li>
        </ul>
      </div>
      <div>
        <h2>{{ __('site.footer.app') }}</h2>
        <ul>
          <li><a href="/download">{{ __('site.footer.download') }}</a></li>
          @if(config('demo.enabled'))<li><a href="/demo">{{ __('site.footer.demo') }}</a></li>@endif
        </ul>
        <p class="tag">{{ __('site.footer.app_body') }}</p>
      </div>
    </div>
    <div class="bottom">
      <span>{{ __('site.footer.rights', ['year' => date('Y')]) }}</span>
      <span class="regions">@foreach(__('site.footer.regions') as $r)<span>{{ $r }}</span>@endforeach</span>
    </div>
  </div>
</footer>
<script src="/landing/site.js?v={{ @filemtime(public_path('landing/site.js')) }}" defer></script>
</body>
</html>
