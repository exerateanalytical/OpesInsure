{{-- Contact ("/contact"). Design source: screens/public_pages/03_contact_page.png. Channels come from platform settings; nothing is invented. --}}
@extends('public.layout')
@php $ct = __('desk.contact'); @endphp
@section('title', __('site.contact.title').' — OpesInsure')
@section('description', $ct['lede'])
@section('content')
<section class="ct-hero" aria-labelledby="ct-title">
  <img class="ct-map" src="/landing/img/desk/pattern.webp" alt="" aria-hidden="true">
  <div class="ct-photo" aria-hidden="true"><picture><source type="image/webp" srcset="/landing/img/desk/hero-contact.webp"><img src="/landing/img/desk/hero-contact.jpg" alt="" width="676" height="235" fetchpriority="high"></picture></div>
  <div class="wrap-x">
    <p class="d-eyebrow">{{ $ct['eyebrow'] }}</p>
    <h1 id="ct-title">{{ $ct['h1'] }}</h1>
    <p class="lede">{{ $ct['lede'] }}</p>
    <ul class="ct-pills">
      @foreach($ct['pills'] as $i => [$t, $d])
        <li><span class="gold-ic">@include('public.partials.i', ['n' => ['headset', 'shield', 'users'][$i]])</span><span><b>{{ $t }}</b><small>{{ $d }}</small></span></li>
      @endforeach
    </ul>
  </div>
</section>

<div class="wrap-x ct-grid">
  <aside class="ct-left">
    <div class="panel">
      <h2 class="ph">{{ $ct['channels'] }}</h2>
      <ul class="chan">
        @if($contacts['phone'])<li><span class="sq blue">@include('public.partials.i', ['n' => 'phone'])</span><b>{{ $ct['phone'] }}</b><a href="tel:{{ preg_replace('/[^+\d]/', '', $contacts['phone']) }}">{{ $contacts['phone'] }}</a>@include('public.partials.i', ['n' => 'chev'])</li>@endif
        @if($contacts['email'])<li><span class="sq blue">@include('public.partials.i', ['n' => 'mail'])</span><b>{{ $ct['email'] }}</b><a href="mailto:{{ $contacts['email'] }}">{{ $contacts['email'] }}<small>{{ $ct['reply'] }}</small></a>@include('public.partials.i', ['n' => 'chev'])</li>@endif
        @if($contacts['whatsapp'] && $contacts['whatsapp_url'])<li><span class="sq green">@include('public.partials.i', ['n' => 'wa'])</span><b>{{ $ct['whatsapp'] }}</b><a href="{{ $contacts['whatsapp_url'] }}" rel="noopener">{{ $contacts['whatsapp'] }}<small>{{ $ct['wa_d'] }}</small></a>@include('public.partials.i', ['n' => 'chev'])</li>@endif
        <li><span class="sq blue">@include('public.partials.i', ['n' => 'chat'])</span><b>{{ $ct['web'] }}</b><a href="#ct-form">{{ $ct['web_d'] }}</a>@include('public.partials.i', ['n' => 'chev'])</li>
        <li><span class="sq blue">@include('public.partials.i', ['n' => 'pin'])</span><b>{{ $ct['where_t'] }}</b><a href="/providers">{{ $ct['where_link'] }}</a>@include('public.partials.i', ['n' => 'chev'])</li>
      </ul>
    </div>
    @if($contacts['phone'])
      <a class="panel claims-line" href="tel:{{ preg_replace('/[^+\d]/', '', $contacts['phone']) }}"><span class="sq orange">@include('public.partials.i', ['n' => 'phone'])</span><span><b>{{ $ct['claims_t'] }}</b><small>{{ $ct['claims_d'] }}</small><strong>{{ $contacts['phone'] }}</strong></span>@include('public.partials.i', ['n' => 'chev'])</a>
    @else
      <a class="panel claims-line" href="/claims"><span class="sq orange">@include('public.partials.i', ['n' => 'shield'])</span><span><b>{{ $ct['claims_t'] }}</b><small>{{ $ct['claims_d'] }}</small></span>@include('public.partials.i', ['n' => 'chev'])</a>
    @endif
  </aside>

  <section class="panel ct-form" id="ct-form" aria-labelledby="form-t">
    <h2 id="form-t" class="ph">{{ $ct['form_t'] }}</h2>
    <p class="muted">{{ $ct['form_d'] }}</p>
    @if(session('contact_sent'))
      <div class="note ok" role="status">{{ session('contact_sent') === 'WEB' ? __('site.contact.sent_generic') : __('site.contact.sent', ['ref' => session('contact_sent')]) }}</div>
    @endif
    @if($errors->any())<div class="note" role="alert">{{ __('site.errors.check') }}</div>@endif
    <form method="post" action="/contact" novalidate>
      @csrf
      <div class="hp" aria-hidden="true"><label for="c-website">Website</label><input id="c-website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
      <div class="dgrid2">
        <div class="dfield"><label for="c-name">{{ __('site.contact.name') }} <i>*</i></label>
          <input id="c-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" placeholder="{{ $ct['name_ph'] }}" @error('name') aria-invalid="true" aria-describedby="e-name" @enderror>
          @error('name')<p class="err" id="e-name">{{ $message }}</p>@enderror</div>
        <div class="dfield"><label for="c-email">{{ __('site.contact.email_field') }} <i>*</i></label>
          <input id="c-email" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" placeholder="{{ $ct['email_ph'] }}" @error('email') aria-invalid="true" aria-describedby="e-email" @enderror>
          @error('email')<p class="err" id="e-email">{{ $message }}</p>@enderror</div>
        <div class="dfield"><label for="c-phone">{{ __('site.contact.phone_field') }}</label>
          <span class="tel"><span class="cc"><img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 3 2'%3E%3Cpath fill='%23007a5e' d='M0 0h1v2H0z'/%3E%3Cpath fill='%23ce1126' d='M1 0h1v2H1z'/%3E%3Cpath fill='%23fcd116' d='M2 0h1v2H2z'/%3E%3C/svg%3E" alt="" width="18" height="12">+237</span><input id="c-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32" autocomplete="tel" placeholder="{{ $ct['phone_ph'] }}"></span></div>
        <div class="dfield"><label for="c-topic">{{ $ct['subject'] }} <i>*</i></label>
          <select id="c-topic" name="topic">@foreach(__('site.contact.topics') as $k => $label)<option value="{{ $k }}" @selected(old('topic', $topic) === $k)>{{ $label }}</option>@endforeach</select></div>
        <div class="dfield full"><label for="c-message">{{ __('site.contact.message') }} <i>*</i></label>
          <textarea id="c-message" name="message" required minlength="20" maxlength="5000" placeholder="{{ $ct['message_ph'] }}" data-count="c-count" aria-describedby="h-message @error('message') e-message @enderror">{{ old('message') }}</textarea>
          <p class="hint" id="h-message"><span>{{ __('site.contact.message_hint') }}</span><span id="c-count">{{ mb_strlen(old('message', '')) }}/5000</span></p>
          @error('message')<p class="err" id="e-message">{{ $message }}</p>@enderror</div>
      </div>
      <button class="dbtn dbtn-primary wide lg" type="submit">@include('public.partials.i', ['n' => 'send']){{ __('site.contact.send') }}</button>
    </form>
  </section>

  <aside class="panel ct-where">
    <h2 class="ph">{{ $ct['where_t'] }}</h2>
    <p class="muted">{{ $ct['where_d'] }}</p>
    <div class="where-map"><img src="/landing/img/desk/map-orange.webp" alt="" loading="lazy" width="600" height="600"><img class="pin" src="/landing/img/pin-cameroon-64.webp" alt="" width="64" height="90" loading="lazy"><span>Cameroon · CEMAC</span></div>
    <a class="link" href="/providers">{{ $ct['where_link'] }} @include('public.partials.i', ['n' => 'arrow'])</a>
  </aside>
</div>

<div class="wrap-x">
  <section class="panel ct-strip" aria-label="{{ __('site.footer.support') }}">
    <div>@include('public.partials.i', ['n' => 'help'])<div><h2>{{ $ct['faq'][0] }}</h2><p>{{ $ct['faq'][1] }}</p><a class="dbtn dbtn-outline sm" href="/faq">{{ $ct['faq'][2] }} @include('public.partials.i', ['n' => 'arrow'])</a></div></div>
    <div>@include('public.partials.i', ['n' => 'clock'])<div><h2>{{ $ct['hours'][0] }}</h2><p>{{ $ct['hours'][1] }}</p></div></div>
    <div>@include('public.partials.i', ['n' => 'handshake'])<div><h2>{{ $ct['partner'][0] }}</h2><p>{{ $ct['partner'][1] }}</p><a class="dbtn dbtn-outline sm" href="/partners">{{ $ct['partner'][2] }} @include('public.partials.i', ['n' => 'arrow'])</a></div></div>
    <div>@include('public.partials.i', ['n' => 'doc'])<div><h2>{{ $ct['media'][0] }}</h2><p>{{ $ct['media'][1] }}</p><a class="dbtn dbtn-outline sm" href="/contact?topic=support#ct-form">{{ $ct['media'][2] }} @include('public.partials.i', ['n' => 'arrow'])</a></div></div>
  </section>
</div>
@endsection
