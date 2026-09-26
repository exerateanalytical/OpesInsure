{{-- Website sign-in / sign-up shell. Design source: screens/auth/01_website_login_page.png, 02_website_signup_page.png --}}
@php
  $A = __('desk.auth');
  $join = $join ?? false;
  $locale = app()->getLocale();
  $langUrl = fn (string $l) => request()->fullUrlWithQuery(['lang' => $l]);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — OpesInsure</title>
<meta name="description" content="{{ $A['sub'] }}">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#031C44">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/landing/img/favicon-32.png">
<link rel="preload" href="/fonts/manrope/manrope-latin-variable.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/landing/site.css?v={{ @filemtime(public_path('landing/site.css')) }}">
</head>
<body class="auth-body">
@include('public.partials.icons')
<div class="auth-shell">
  <header class="auth-top">
    <a class="auth-brand" href="/">@include('public.partials.img', ['name' => 'app-icon', 'sizes' => '72px', 'eager' => true])<span><strong>Opes<b>Insure</b></strong><small>{{ $A['tagline'] }}</small></span></a>
    <nav aria-label="{{ __('site.nav.main') }}">
      @foreach(['/insurance', '/partners', '/partners', '/about', '/faq'] as $i => $href)<a href="{{ $href }}">{{ $A['nav'][$i] }}</a>@endforeach
      <span class="lang light"><a href="{{ $langUrl('en') }}" @if($locale === 'en') aria-current="true" @endif>EN</a><a href="{{ $langUrl('fr') }}" @if($locale === 'fr') aria-current="true" @endif>FR</a></span>
    </nav>
  </header>
  <main class="auth-main">
    <section class="auth-art" aria-hidden="true">
      <div class="auth-copy">
        <p class="auth-word">@if($join){{ $A['join'] }} @endif Opes<b>Insure</b></p>
        <p class="auth-tag">{{ $A['tagline'] }}</p>
        <span class="auth-rule"></span>
        <p class="auth-pitch">{{ $join ? $A['pitch_join'] : $A['pitch'] }}</p>
      </div>
      <img class="auth-map" src="/landing/img/desk/map-orange.webp" alt="" width="600" height="600">
      <p class="auth-sub">{{ $A['sub'] }}</p>
      <picture class="auth-photo"><source type="image/webp" srcset="/landing/img/desk/auth-hero.webp"><img src="/landing/img/desk/auth-hero.jpg" alt="" width="822" height="423" fetchpriority="high"></picture>
      <ul class="auth-trust">
        @foreach($join ? $A['trust_join'] : $A['trust'] as $i => [$t, $d])
          <li><span class="ring">@include('public.partials.i', ['n' => ['shield', 'lock', 'check'][$i]])</span><span><b>{{ $t }}</b><small>{{ $d }}</small></span></li>
        @endforeach
      </ul>
      <div class="auth-band"></div>
    </section>
    <section class="auth-card" aria-labelledby="auth-title">
      @yield('card')
    </section>
  </main>
  <footer class="auth-foot">
    <p class="motto">@foreach($A['motto'] as $i => $m)@if($i)<span>•</span>@endif{{ $m }}@endforeach</p>
    <nav>@foreach(['/privacy', '/terms', '/faq', '/contact'] as $i => $href)<a href="{{ $href }}">{{ $A['footer'][$i] }}</a>@endforeach</nav>
  </footer>
</div>
<p class="auth-copyr">{{ __('site.footer.rights', ['year' => date('Y')]) }}</p>
@php $authCfg = ['api' => url('/api/v1'), 'err' => ['generic' => $A['err_generic'], 'login' => $A['err_login'], 'match' => $A['err_match'], 'terms' => $A['err_terms'], 'phone' => $A['err_phone'], 'code' => $A['err_code']], 'wait' => $A['wait'], 'codeSent' => $A['code_sent'], 'locale' => $locale]; @endphp
<script>window.OPES_AUTH = {!! json_encode($authCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};</script>
<script src="/landing/auth.js?v={{ @filemtime(public_path('landing/auth.js')) }}" defer></script>
</body>
</html>
