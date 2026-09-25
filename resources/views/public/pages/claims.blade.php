@extends('public.layout')
@section('title', __('site.claims.title').' — OpesInsure')
@section('description', __('site.claims.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.claims.title'), 'lede' => __('site.claims.lede')])
<section><div class="wrap two-col">
  <div>
    <h2>{{ __('site.claims.steps_title') }}</h2>
    <ol class="numbered">
      @foreach(__('site.claims.steps') as [$t, $d])
        <li><div><b>{{ $t }}</b><p>{{ $d }}</p></div></li>
      @endforeach
    </ol>
    <div class="btn-row" style="margin-top:26px">
      <a class="btn btn-gold" href="{{ url('/app/claim/new') }}">{{ __('site.claims.cta') }} @include('public.partials.i', ['n' => 'arrow'])</a>
      <a class="btn btn-outline" href="/download">{{ __('site.common.download_android') }}</a>
    </div>
  </div>
  <aside class="card">
    <h2 style="font-size:20px">{{ __('site.claims.tips_title') }}</h2>
    <ul style="padding-left:20px;margin:10px 0 16px">@foreach(__('site.claims.tips') as $t)<li style="margin-bottom:8px">{{ $t }}</li>@endforeach</ul>
    <p>{{ __('site.claims.no_app') }}</p>
    <p style="margin-top:14px"><a class="link" href="/contact?topic=claim">{{ __('site.common.contact_support') }} @include('public.partials.i', ['n' => 'arrow'])</a></p>
  </aside>
</div></section>
@endsection
