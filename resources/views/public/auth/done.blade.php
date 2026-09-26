@php $A = __('desk.auth'); @endphp
<div data-step="done" hidden>
  <span class="done-ic">@include('public.partials.i', ['n' => 'check'])</span>
  <h1 data-done-title data-tpl="{{ $A['done_t'] }}">{{ $A['done_t'] }}</h1>
  <p class="auth-lead">{{ $A['done_d'] }}</p>
  <a class="abtn primary" href="{{ url('/app/policies') }}">{{ $A['open_app'] }} @include('public.partials.i', ['n' => 'arrow'])</a>
  <a class="abtn outline" href="/download">@include('public.partials.i', ['n' => 'download']){{ $A['download'] }}</a>
  <a class="abtn ghost" href="/insurance">{{ $A['browse'] }}</a>
  <button class="alink center" type="button" data-signout>@include('public.partials.i', ['n' => 'logout']){{ $A['sign_out'] }}</button>
</div>
