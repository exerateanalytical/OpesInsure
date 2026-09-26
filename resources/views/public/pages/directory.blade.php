{{-- Category directory ("/insurance/{line}"). Design source: screens/category_directories/0*_*_insurance_directory.png --}}
@extends('public.layout')
@php [$lineTitle, $lineLede, $points] = __('desk.lines.'.$slug); @endphp
@section('title', $lineTitle.' — OpesInsure')
@section('description', $lineLede)
@section('content')
<section class="dir-hero" aria-labelledby="dir-title">
  <div class="wrap-x dir-grid">
    <div class="dir-copy">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/">{{ __('desk.home') }}</a>@include('public.partials.i', ['n' => 'chev'])<a href="/insurance">{{ __('desk.tab.all') }}</a>@include('public.partials.i', ['n' => 'chev'])<span aria-current="page">{{ $lineTitle }}</span></nav>
      <h1 id="dir-title">{{ $lineTitle }}</h1>
      <p class="lede">{{ $lineLede }}</p>
    </div>
    <div class="dir-art"><picture><source type="image/webp" srcset="/landing/img/desk/hero-{{ $slug }}.webp"><img src="/landing/img/desk/hero-{{ $slug }}.jpg" alt="" fetchpriority="high"></picture></div>
    <ul class="dir-points">
      @foreach($points as $i => $pt)
        <li><span class="navy-ic">@include('public.partials.i', ['n' => ['shield', 'headset', 'handshake'][$i]])</span>{{ $pt }}</li>
      @endforeach
    </ul>
  </div>
</section>
@include('public.partials.catalogue', ['variant' => 'directory'])
@endsection
