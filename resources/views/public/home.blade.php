<x-public.layout :title="__('public.home_title')">
<div class="hero"><div class="wrap">
  <span class="eyebrow">{{ __('public.home_eyebrow') }}</span>
  <h1>{{ __('public.home_heading') }}</h1>
  <p class="lede">{{ __('public.home_lede') }}</p>
  <div class="btn-row">
    <a class="btn btn-primary" href="/download">{{ __('public.home_cta_download') }}</a>
    <a class="btn btn-ghost" href="/admin">{{ __('public.home_cta_portal') }}</a>
  </div>
</div></div>

<section><div class="wrap">
  <h2>{{ __('public.features_heading') }}</h2>
  <p class="sub">{{ __('public.features_sub') }}</p>
  <div class="grid">
    @foreach(__('public.features') as $feature)
      <article class="card">
        <div class="ico" aria-hidden="true">{{ $feature['icon'] }}</div>
        <h3>{{ $feature['title'] }}</h3>
        <p>{{ $feature['body'] }}</p>
      </article>
    @endforeach
  </div>
</div></section>
</x-public.layout>
