@extends('public.auth.layout', ['join' => true])
@php $A = __('desk.auth'); $terms = '<a href="/terms">'.e($A['terms']).'</a>'; $privacy = '<a href="/privacy">'.e($A['privacy']).'</a>'; @endphp
@section('title', $A['up_t'])
@section('card')
<div data-step="form">
  <h1 id="auth-title">{{ $A['up_t'] }}</h1>
  <p class="auth-lead"><span class="big">{{ $A['up_d'] }}</span><br>{{ $A['up_d2'] }}</p>
  <div class="auth-err" role="alert" hidden></div>
  <form id="signup-form" novalidate>
    <div class="afield"><label for="s-name">{{ $A['name'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'user'])<input id="s-name" name="full_name" autocomplete="name" required maxlength="120" placeholder="{{ $A['name_ph'] }}"></span></div>
    <div class="agrid2">
      <div class="afield"><label for="s-email">{{ $A['email'] }}</label>
        <span class="ain">@include('public.partials.i', ['n' => 'mail'])<input id="s-email" name="email" type="email" autocomplete="email" maxlength="190" placeholder="{{ $A['email_ph'] }}"></span></div>
      <div class="afield"><label for="s-phone">{{ $A['phone'] }}</label>
        <span class="ain">@include('public.partials.i', ['n' => 'phone'])<input id="s-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="{{ $A['phone_ph'] }}"></span></div>
    </div>
    <div class="afield"><label for="s-type">{{ $A['type'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'users'])<select id="s-type" name="account_type" data-partner-switch>@foreach($A['types'] as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></span></div>
    <div class="apartner" hidden><p>{{ $A['partner_note'] }}</p><a class="abtn outline" href="/partners">{{ $A['partner_btn'] }} @include('public.partials.i', ['n' => 'arrow'])</a></div>
    <div data-customer>
      <div class="agrid2">
        <div class="afield"><label for="s-pass">{{ $A['password'] }}</label>
          <span class="ain">@include('public.partials.i', ['n' => 'lock'])<input id="s-pass" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="{{ $A['password_ph'] }}"><button class="eye" type="button" aria-label="{{ $A['show'] }}" data-toggle-pw="s-pass">@include('public.partials.i', ['n' => 'eye'])</button></span></div>
        <div class="afield"><label for="s-pass2">{{ $A['confirm'] }}</label>
          <span class="ain">@include('public.partials.i', ['n' => 'lock'])<input id="s-pass2" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required placeholder="{{ $A['confirm_ph'] }}"><button class="eye" type="button" aria-label="{{ $A['show'] }}" data-toggle-pw="s-pass2">@include('public.partials.i', ['n' => 'eye'])</button></span></div>
      </div>
      <p class="ahint">{{ $A['pw_hint'] }}</p>
      <label class="acheck"><input type="checkbox" name="terms" required> <span>{!! __('desk.auth.agree', ['terms' => $terms, 'privacy' => $privacy]) !!}</span></label>
      <button class="abtn primary" type="submit">{{ $A['up_btn'] }} @include('public.partials.i', ['n' => 'arrow'])</button>
      <a class="abtn outline" href="/login">{{ $A['in_link'] }}</a>
    </div>
  </form>
  <p class="afine center">{{ $A['have'] }} <a href="/login"><b>{{ $A['in_link'] }}</b></a></p>
</div>

<div data-step="code" hidden>
  <h1>{{ $A['code'] }}</h1>
  <p class="auth-lead" data-code-msg></p>
  <div class="auth-err" role="alert" hidden></div>
  <form id="code-form" novalidate>
    <div class="afield"><label for="s-code">{{ $A['code'] }}</label>
      <span class="ain">@include('public.partials.i', ['n' => 'lock'])<input id="s-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required placeholder="{{ $A['code_ph'] }}"></span></div>
    <button class="abtn primary" type="submit" data-verify-label="{{ $A['verify'] }}">{{ $A['verify'] }}</button>
  </form>
</div>

@include('public.auth.done')
@endsection
