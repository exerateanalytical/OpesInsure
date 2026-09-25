@extends('public.layout')
@section('title', __('site.open_app.title').' — OpesInsure')
@push('head')<meta name="robots" content="noindex">@endpush
@section('content')
@include('public.partials.page-hero', ['title' => __('site.open_app.title'), 'lede' => __('site.open_app.lede')])
<section><div class="wrap two-col">
  <div>
    <p style="margin-bottom:18px">{{ __('site.open_app.install') }}</p>
    <div class="stores">
      <a class="store" href="/download">@include('public.partials.i', ['n' => 'play'])<span><small>{{ __('site.pocket.android_small') }}</small><strong>{{ __('site.pocket.android') }}</strong></span></a>
      <span class="store soon">@include('public.partials.i', ['n' => 'apple'])<span><small>{{ __('site.pocket.ios_small') }}</small><strong>{{ __('site.pocket.ios') }}</strong></span><span class="chip warn">{{ __('site.pocket.soon') }}</span></span>
    </div>
    <p style="margin-top:18px;color:var(--muted)">{{ __('site.open_app.ios') }}</p>
    <p style="margin-top:22px"><a class="link" href="/">{{ __('site.common.back_home') }} @include('public.partials.i', ['n' => 'arrow'])</a></p>
  </div>
  <div class="card"><p style="font-size:14px;color:var(--muted)">{{ __('site.common.open_app') }}</p><code style="display:block;margin-top:8px;word-break:break-all">{{ $path }}</code></div>
</div></section>
@endsection
