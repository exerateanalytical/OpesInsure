<x-public.layout :title="__('public.download_title')">
<div class="hero"><div class="wrap">
<div class="hero-flex">
<img class="app-icon" src="{{ asset('img/app-icon.png') }}" alt="{{ __('public.download_title') }}" width="1254" height="1254">
<div>
  <span class="eyebrow">{{ __('public.download_eyebrow') }}</span>
  <h1>{{ __('public.download_heading') }}</h1>
  <p class="lede">{{ __('public.download_lede') }}</p>
  <div class="btn-row">
    @if($android)
      <a class="btn btn-primary" href="{{ $android }}" download>
        {{ __('public.download_android') }}@if($androidSize) <span style="opacity:.75;font-weight:600">({{ $androidSize }})</span>@endif
      </a>
    @else
      <span class="btn btn-primary" aria-disabled="true">{{ __('public.download_android_pending') }}</span>
    @endif
    @if($ios)
      <a class="btn btn-ghost" href="{{ $ios }}">{{ __('public.download_ios') }}</a>
    @else
      <span class="btn btn-ghost" aria-disabled="true">{{ __('public.download_ios_pending') }}</span>
    @endif
  </div>
</div>
</div>
</div></div>

@unless($android && $ios)
<section style="padding-bottom:0"><div class="wrap">
  <div class="note">
    <span aria-hidden="true">&#9888;</span>
    <span><strong>{{ __('public.pending_title') }}</strong><br>{{ __('public.pending_body') }}</span>
  </div>
</div></section>
@endunless

<section><div class="wrap">
  <h2>{{ __('public.install_heading') }}</h2>
  <p class="sub">{{ __('public.install_sub') }}</p>
  <div class="grid">
    <article class="card">
      <div class="ico" aria-hidden="true">&#9654;</div>
      <h3>{{ __('public.android_title') }}</h3>
      <p>{{ __('public.android_body', ['version' => $version, 'min' => $minAndroid]) }}</p>
    </article>
    <article class="card">
      <div class="ico" aria-hidden="true">&#63743;</div>
      <h3>{{ __('public.ios_title') }}</h3>
      <p>{{ __('public.ios_body', ['version' => $version, 'min' => $minIos]) }}</p>
    </article>
    <article class="card">
      <div class="ico" aria-hidden="true">&#128274;</div>
      <h3>{{ __('public.security_title') }}</h3>
      <p>{{ __('public.security_body') }}</p>
    </article>
  </div>
</div></section>

<section class="alt"><div class="wrap">
  <h2>{{ __('public.capabilities_heading') }}</h2>
  <p class="sub">{{ __('public.capabilities_sub') }}</p>
  <div class="grid">
    @foreach(__('public.capabilities') as $cap)
      <article class="card">
        <h3>{{ $cap['title'] }}</h3>
        <p>{{ $cap['body'] }}</p>
      </article>
    @endforeach
  </div>
</div></section>
</x-public.layout>
