@extends('public.layout')
@section('title', __('site.how_page.title').' — OpesInsure')
@section('description', __('site.how_page.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.how_page.title'), 'lede' => __('site.how_page.lede')])
<section><div class="wrap">
  <h2>{{ __('site.how.title') }}</h2>
  <p class="sub">{{ __('site.how.sub') }}</p>
  <ol class="numbered">
    @foreach(__('site.how.steps') as $i => [$t, $d])
      <li><div><b>{{ $t }}</b><p>{{ __('site.how_page.details')[$i] }}</p></div></li>
    @endforeach
  </ol>
  <div class="btn-row" style="margin-top:30px">
    <a class="btn btn-gold" href="{{ url('/app/compare') }}">{{ __('site.hero.cta_compare') }} @include('public.partials.i', ['n' => 'arrow'])</a>
    <a class="btn btn-outline" href="/download">{{ __('site.common.download_android') }}</a>
  </div>
</div></section>
<section class="alt"><div class="wrap two-col">
  <div><h2>{{ __('site.how_page.verify_title') }}</h2><p class="sub">{{ __('site.how_page.verify_body') }}</p></div>
  <div class="card"><div class="ico">@include('public.partials.i', ['n' => 'shield'])</div><h3>{{ __('site.faq.items')[5][0] }}</h3><p>{{ __('site.faq.items')[5][1] }}</p></div>
</div></section>
@endsection
