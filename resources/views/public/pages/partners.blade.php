@extends('public.layout')
@section('title', __('site.partners.title').' — OpesInsure')
@section('description', __('site.partners.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.partners.title'), 'lede' => __('site.partners.lede')])
<section><div class="wrap">
  <div class="grid" style="margin-top:0">
    @foreach(__('site.partners.who') as $i => [$t, $d])
      <article class="card"><div class="ico">@include('public.partials.i', ['n' => ['shield', 'handshake', 'users'][$i]])</div><h2 style="font-size:19px">{{ $t }}</h2><p>{{ $d }}</p></article>
    @endforeach
  </div>
</div></section>
<section class="alt"><div class="wrap two-col">
  <div>
    <h2>{{ __('site.partners.how_title') }}</h2>
    <ol class="numbered">@foreach(__('site.partners.how') as $t)<li><div><p style="color:var(--ink)">{{ $t }}</p></div></li>@endforeach</ol>
  </div>
  <aside class="card">
    <div class="ico">@include('public.partials.i', ['n' => 'handshake'])</div>
    <h2 style="font-size:20px">{{ __('site.partner.title') }}</h2>
    <p style="margin-bottom:18px">{{ __('site.partner.body') }}</p>
    <div class="btn-row">
      <a class="btn btn-gold" href="/contact?topic=partner">{{ __('site.partners.cta') }} @include('public.partials.i', ['n' => 'arrow'])</a>
    </div>
    @if($contacts['partner_email'])<p style="margin-top:16px">{{ __('site.partners.email_label') }}: <a class="link" href="mailto:{{ $contacts['partner_email'] }}">{{ $contacts['partner_email'] }}</a></p>@endif
    @if($stats['insurers'])<p style="margin-top:12px;font-size:14px">{{ __('site.providers_page.totals', ['insurers' => $stats['insurers'], 'brokers' => $stats['brokers']]) }} — <a class="link" href="/providers">{{ __('site.providers.view_all') }}</a></p>@endif
  </aside>
</div></section>
@endsection
