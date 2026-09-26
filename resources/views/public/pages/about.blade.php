{{-- About ("/about"). Design source: screens/public_pages/02_about_page.png --}}
@extends('public.layout')
@php $a = __('desk.about'); @endphp
@section('title', __('site.about.title').' — OpesInsure')
@section('description', $a['lede'])
@section('content')
<section class="ab-hero" aria-labelledby="ab-title">
  <div class="ab-photo" aria-hidden="true"><picture><source type="image/webp" srcset="/landing/img/desk/hero-about.webp"><img src="/landing/img/desk/hero-about.jpg" alt="" width="529" height="352" fetchpriority="high"></picture></div>
  <div class="wrap-x">
    <div class="ab-copy">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/">{{ __('desk.home') }}</a>@include('public.partials.i', ['n' => 'chev'])<span aria-current="page">{{ $a['crumb'] }}</span></nav>
      <h1 id="ab-title">{{ $a['h1a'] }}<br>Opes<span class="gold">Insure</span></h1>
      <p class="ab-tag">{{ $a['tagline'] }}</p>
      <p class="lede">{{ $a['lede'] }}</p>
      <div class="btn-row"><a class="dbtn dbtn-primary lg" href="#story">{{ $a['story_btn'] }}</a><a class="dbtn dbtn-outline lg" href="/partners">{{ $a['partner_btn'] }}</a></div>
    </div>
  </div>
</section>

<div class="wrap-x">
  <section class="mvv panel" aria-label="{{ $a['mission'][0] }}">
    <div><span class="round blue">@include('public.partials.i', ['n' => 'target'])</span><div><h2>{{ $a['mission'][0] }}</h2><p>{{ $a['mission'][1] }}</p></div></div>
    <div><span class="round gold">@include('public.partials.i', ['n' => 'eye'])</span><div><h2>{{ $a['vision'][0] }}</h2><p>{{ $a['vision'][1] }}</p></div></div>
    <div><span class="round blue">@include('public.partials.i', ['n' => 'gem'])</span><div><h2>{{ $a['values_t'] }}</h2><ul class="ticks">@foreach($a['values'] as $v)<li>@include('public.partials.i', ['n' => 'check']){{ $v }}</li>@endforeach</ul></div></div>
  </section>

  <section class="ab-split" id="story" aria-labelledby="story-title">
    <picture class="ab-img"><source type="image/webp" srcset="/landing/img/desk/about-building.webp"><img src="/landing/img/desk/about-building.jpg" alt="" width="369" height="199" loading="lazy"></picture>
    <div><p class="d-eyebrow">{{ $a['story'][0] }}</p><h2 id="story-title">{{ $a['story'][1] }}</h2><p>{{ $a['story'][2] }}</p><p>{{ $a['story'][3] }}</p></div>
  </section>

  <section class="ab-what" aria-labelledby="what-title">
    <div><p class="d-eyebrow">{{ $a['what'][0] }}</p><h2 id="what-title">{{ $a['what'][1] }}</h2><p>{{ $a['what'][2] }}</p></div>
    <ul class="what-tiles">
      @foreach($a['tiles'] as $i => [$t, $d])
        <li>@include('public.partials.i', ['n' => ['search', 'doc', 'shield', 'doc', 'headset', 'users'][$i]])<b>{{ $t }}</b><span>{{ $d }}</span></li>
      @endforeach
    </ul>
  </section>

  <section class="ab-eco" aria-labelledby="eco-t">
    <div><p class="d-eyebrow">{{ $a['eco'][0] }}</p><h2 id="eco-t">{{ $a['eco'][1] }}</h2><p>{{ $a['eco'][2] }}</p></div>
    <ul class="eco-tiles">
      @foreach($a['eco_tiles'] as $i => [$t, $d])
        <li class="c{{ $i }}">@include('public.partials.i', ['n' => ['bank', 'users', 'user', 'users'][$i]])<b>{{ $t }}</b><span>{{ $d }}</span></li>
      @endforeach
    </ul>
  </section>

  <section class="ab-split reg" aria-labelledby="reg-t">
    <picture class="ab-img"><source type="image/webp" srcset="/landing/img/desk/about-cima.webp"><img src="/landing/img/desk/about-cima.jpg" alt="" width="374" height="162" loading="lazy"></picture>
    <div><p class="d-eyebrow">{{ $a['reg'][0] }}</p><h2 id="reg-t">{{ $a['reg'][1] }}</h2><p>{{ $a['reg'][2] }}</p>
      <ul class="reg-items">@foreach($a['reg_items'] as $i => $r)<li>@include('public.partials.i', ['n' => ['shield', 'lock', 'doc', 'scale'][$i]]){{ $r }}</li>@endforeach</ul>
    </div>
  </section>
</div>

<section class="ab-commit" aria-labelledby="commit-t">
  <img class="ab-map" src="/landing/img/desk/map-dots.webp" alt="" aria-hidden="true" loading="lazy">
  <div class="wrap-x">
    <div><p class="d-eyebrow">{{ $a['commit'][0] }}</p><h2 id="commit-t">{{ $a['commit'][1] }}</h2><p>{{ $a['commit'][2] }}</p></div>
    <div class="btn-row"><a class="dbtn dbtn-primary lg" href="/insurance">{{ __('site.nav.compare') }}</a><a class="dbtn dbtn-outline lg" href="/partners">{{ $a['partner_btn'] }}</a></div>
  </div>
</section>
@endsection
