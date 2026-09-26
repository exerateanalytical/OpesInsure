{{--
  Signed-in account shell. Design source: screens/compare_buy_flow/03..24 (header with user menu,
  tribal side border, left sidebar, banner hero with breadcrumb + title). Pages extend this with:
    @extends('public.account.layout', ['title' => ..., 'lede' => ..., 'crumbs' => [[label, href|null], ...], 'active' => 'claims'])
  and fill @section('content'); page data is fetched client-side with window.Opes (public/landing/portal/portal.js).
--}}
@php
  $A = __('account');
  $locale = app()->getLocale();
  $active = $active ?? '';
  $langUrl = fn (string $l) => request()->fullUrlWithQuery(['lang' => $l]);
  $side = [
    ['dashboard', '/account', 'home', null],
    ['policies', '/account/policies', 'doc', null],
    ['quotes', '/account/quotes', 'compare', null],
    ['claims', '/account/claims', 'shield', null],
    ['payments', '/account/payments', 'card', null],
    ['documents', '/account/documents', 'doc', null],
    ['vehicles', '/account/vehicles', 'motor', null],
    ['customers', '/account/customers', 'users', 'agent'],
    ['leads', '/account/leads', 'target', 'agent'],
    ['reports', '/account/reports', 'grid', 'agent'],
    ['commissions', '/account/commissions', 'piggy', 'agent'],
    ['desk', '/account/claims-desk', 'scale', 'officer'],
    ['profile', '/account/profile', 'user', null],
    ['notifications', '/account/notifications', 'bell', null],
    ['support', '/account/support', 'headset', null],
  ];
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — OpesInsure</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#031C44">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="preload" href="/fonts/manrope/manrope-latin-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/landing/site.css?v={{ @filemtime(public_path('landing/site.css')) }}">
<link rel="stylesheet" href="/landing/portal/portal.css?v={{ @filemtime(public_path('landing/portal/portal.css')) }}">
@stack('head')
</head>
<body class="acct-body">
@include('public.partials.icons')
<a class="skip" href="#acct-main">{{ __('site.skip') }}</a>
<header class="acct-top">
  <a class="acct-brand" href="/">@include('public.partials.img', ['name' => 'app-icon', 'sizes' => '40px', 'eager' => true])<span>Opes<b>Insure</b><small>{{ $A['motto'] }}</small></span></a>
  <nav class="acct-nav" aria-label="{{ __('site.nav.main') }}">
    <a href="/">{{ __('desk.home') }}</a>
    <a href="/insurance">{{ $A['nav']['products'] }}</a>
    <a href="/compare">{{ $A['nav']['compare'] }}</a>
    <a href="/providers">{{ __('site.nav.providers') }}</a>
    <a href="/account/claims" @if(in_array($active, ['claims', 'desk'], true)) aria-current="page" @endif>{{ __('site.nav.claims') }}</a>
    <a href="/contact">{{ $A['nav']['support'] }}</a>
  </nav>
  <div class="acct-actions">
    <a class="acct-ic" href="/account/notifications" aria-label="{{ $A['side']['notifications'] }}">@include('public.partials.i', ['n' => 'bell'])<span class="dot" data-unread hidden></span></a>
    <nav class="lang light" aria-label="{{ __('site.nav.language') }}"><a href="{{ $langUrl('en') }}" @if($locale === 'en') aria-current="true" @endif>EN</a><a href="{{ $langUrl('fr') }}" @if($locale === 'fr') aria-current="true" @endif>FR</a></nav>
    <details class="acct-user">
      <summary><span class="av" data-user-initials>··</span><span class="who"><b data-user-name>…</b><small data-user-role></small></span>@include('public.partials.i', ['n' => 'chev-down'])</summary>
      <div class="menu">
        <a href="/account/profile">@include('public.partials.i', ['n' => 'user']){{ $A['side']['profile'] }}</a>
        <button type="button" data-signout>@include('public.partials.i', ['n' => 'logout']){{ $A['sign_out'] }}</button>
      </div>
    </details>
  </div>
</header>

<div class="acct-frame">
  <aside class="acct-side" aria-label="{{ $A['account_nav'] }}">
    <nav>
      @foreach($side as [$key, $href, $icon, $role])
        <a href="{{ $href }}" @if($role) data-role="{{ $role }}" hidden @endif @if($active === $key) aria-current="page" @endif>@include('public.partials.i', ['n' => $icon])<span>{{ $A['side'][$key] }}</span>@if($key === 'notifications')<em class="badge" data-unread-count hidden></em>@endif</a>
      @endforeach
    </nav>
    <div class="side-help">
      @include('public.partials.i', ['n' => 'headset'])
      <b>{{ $A['help_t'] }}</b><small>{{ $A['help_d'] }}</small>
      <a class="dbtn dbtn-outline sm" href="/contact?topic=support">@include('public.partials.i', ['n' => 'phone']){{ $A['help_btn'] }}</a>
    </div>
  </aside>

  <main id="acct-main" class="acct-main">
    <section class="acct-hero" aria-labelledby="acct-title">
      <picture class="acct-banner" aria-hidden="true"><source type="image/webp" srcset="/landing/img/desk/banner.webp"><img src="/landing/img/desk/banner.webp" alt="" width="1400" height="788"></picture>
      <div class="acct-hero-in">
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/">{{ __('desk.home') }}</a>@foreach(($crumbs ?? []) as [$label, $href])@include('public.partials.i', ['n' => 'chev'])@if($href)<a href="{{ $href }}">{{ $label }}</a>@else<span aria-current="page">{{ $label }}</span>@endif @endforeach</nav>
        <h1 id="acct-title">@yield('title_prefix'){{ $title }}</h1>
        @isset($lede)<p class="lede">{{ $lede }}</p>@endisset
        @yield('hero_extra')
      </div>
    </section>
    <div class="acct-content" data-page>
      <div class="acct-alert" role="alert" hidden></div>
      @yield('content')
    </div>
  </main>
</div>

@php
  $portalCfg = ['api' => url('/api/v1'), 'locale' => $locale, 'ids' => $ids ?? [], 'path' => $path ?? '/account',
    't' => $A['js']];
@endphp
<script>window.OPES_PORTAL = {!! json_encode($portalCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};</script>
<script src="/landing/portal/portal.js?v={{ @filemtime(public_path('landing/portal/portal.js')) }}"></script>
@stack('scripts')
</body>
</html>
