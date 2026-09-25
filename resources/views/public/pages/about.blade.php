@extends('public.layout')
@section('title', __('site.about.title').' — OpesInsure')
@section('description', __('site.about.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.about.title'), 'lede' => __('site.about.lede')])
<section><div class="wrap">
  <div class="grid" style="margin-top:0">
    @foreach(__('site.about.blocks') as $i => [$t, $d])
      <article class="card"><div class="ico">@include('public.partials.i', ['n' => ['globe', 'shield', 'doc', 'users'][$i]])</div><h2 style="font-size:19px">{{ $t }}</h2><p>{{ $d }}</p></article>
    @endforeach
  </div>
</div></section>
<section class="alt"><div class="wrap">
  <h2>{{ __('site.about.values_title') }}</h2>
  <div class="grid">
    @foreach(__('site.about.values') as [$t, $d])
      <article class="card"><h3>{{ $t }}</h3><p>{{ $d }}</p></article>
    @endforeach
  </div>
  <div class="btn-row" style="margin-top:30px">
    <a class="btn btn-gold" href="/providers">{{ __('site.providers.view_all') }}</a>
    <a class="btn btn-outline" href="/contact">{{ __('site.footer.contact') }}</a>
  </div>
</div></section>
@endsection
