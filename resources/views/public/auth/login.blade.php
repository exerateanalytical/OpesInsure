@extends('public.auth.layout')
@php $A = __('desk.auth'); $terms = '<a href="/terms">'.e($A['terms']).'</a>'; $privacy = '<a href="/privacy">'.e($A['privacy']).'</a>'; @endphp
@section('title', $A['in_t'])
@section('card')
<div data-step="form">
  <h1 id="auth-title">{{ $A['in_t'] }}</h1>
  <p class="auth-lead">{{ $A['in_d'] }}<br>{{ $A['in_d2'] }}</p>
  <div class="auth-err" role="alert" hidden></div>
  <form id="login-form" novalidate>
    <div class="afield"><label for="l-phone">{{ $A['phone'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'user'])<em class="pre">+237</em><input id="l-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="{{ $A['phone_ph'] }}"></span></div>
    <div class="afield" data-pw><label for="l-pass">{{ $A['password'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'lock'])<input id="l-pass" name="password" type="password" autocomplete="current-password" required placeholder="{{ $A['password_ph'] }}"><button class="eye" type="button" aria-label="{{ $A['show'] }}" data-toggle-pw="l-pass">@include('public.partials.i', ['n' => 'eye'])</button></span></div>
    <div class="arow"><span></span><button type="button" class="alink" data-forgot>{{ $A['forgot'] }}</button></div>
    <button class="abtn primary" type="submit">{{ $A['in_btn'] }} @include('public.partials.i', ['n' => 'arrow'])</button>
    <a class="abtn outline" href="/signup">{{ $A['create'] }}</a>
  </form>
  <p class="aor"><span>{{ $A['or'] }}</span></p>
  <div class="asocial">
    <button class="abtn ghost" type="button" data-otp>@include('public.partials.i', ['n' => 'chat']){{ $A['otp_btn'] }}</button>
    <a class="abtn ghost" href="/admin/login">@include('public.partials.i', ['n' => 'bank']){{ $A['staff_link'] }}</a>
  </div>
  <p class="afine">{!! __('desk.auth.agree_in', ['terms' => $terms, 'privacy' => $privacy]) !!}</p>
</div>

<div data-step="code" hidden>
  <h1>{{ $A['code'] }}</h1>
  <p class="auth-lead" data-code-msg></p>
  <div class="auth-err" role="alert" hidden></div>
  <form id="code-form" novalidate>
    <div class="afield"><label for="l-code">{{ $A['code'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'lock'])<input id="l-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required placeholder="{{ $A['code_ph'] }}"></span></div>
    <div class="afield" data-newpw hidden><label for="l-newpw">{{ $A['new_pw'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'lock'])<input id="l-newpw" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="{{ $A['pw_hint'] }}"></span></div>
    <button class="abtn primary" type="submit" data-verify-label="{{ $A['verify'] }}" data-reset-label="{{ $A['reset_btn'] }}">{{ $A['verify'] }}</button>
    <button class="abtn outline" type="button" data-back>{{ $A['back'] }}</button>
  </form>
</div>

@include('public.auth.done')
@endsection
