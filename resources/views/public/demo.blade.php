@extends('public.layout')
@section('title', __('public.demo_title'))
@section('content')
@include('public.partials.page-hero', ['eyebrow' => __('public.demo_eyebrow'), 'title' => __('public.demo_heading'), 'lede' => __('public.demo_lede'), 'img' => 'hero-business'])
<div class="ip">

<section><div class="wrap">
  <div class="note" style="margin-bottom:30px">
    <span aria-hidden="true">&#9888;</span>
    <span><strong>{{ __('public.demo_warning_title') }}</strong><br>{{ __('public.demo_warning_body') }}</span>
  </div>

  <h2>{{ __('public.demo_web_heading') }}</h2>
  <p class="sub">{{ __('public.demo_web_sub', ['password' => $password]) }}</p>
  <div class="tablewrap">
    <table class="demo">
      <thead><tr><th>{{ __('public.demo_col_role') }}</th><th>{{ __('public.demo_col_email') }}</th></tr></thead>
      <tbody>
      @foreach($staff as $a)
        <tr><td>{{ $a['label'] }}</td><td><code>{{ $a['email'] }}</code></td></tr>
      @endforeach
      </tbody>
    </table>
  </div>
</div></section>

<section class="alt"><div class="wrap">
  <h2>{{ __('public.demo_mobile_heading') }}</h2>
  <p class="sub">{{ __('public.demo_mobile_sub', ['otp' => $otp]) }}</p>
  <div class="tablewrap">
    <table class="demo">
      <thead><tr><th>{{ __('public.demo_col_role') }}</th><th>{{ __('public.demo_col_phone') }}</th><th>{{ __('public.demo_col_otp') }}</th></tr></thead>
      <tbody>
      @foreach($mobile as $a)
        <tr><td>{{ $a['label'] }}</td><td><code>{{ $a['phone'] }}</code></td><td><code>{{ $otp }}</code></td></tr>
      @endforeach
      </tbody>
    </table>
  </div>
  <div class="btn-row" style="margin-top:28px">
    <a class="btn btn-primary" href="/download">{{ __('public.home_cta_download') }}</a>
    <a class="btn btn-ghost" href="/admin/login">{{ __('public.nav_portal') }}</a>
  </div>
</div></section>
</div>
@endsection
