@extends('public.layout')
@section('title', __('site.contact.title').' — OpesInsure')
@section('description', __('site.contact.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('site.contact.title'), 'lede' => __('site.contact.lede')])
<section><div class="wrap two-col">
  <div class="card form-card">
    <h2 style="font-size:22px">{{ __('site.contact.form_title') }}</h2>
    @if(session('contact_sent'))
      <div class="note ok" role="status" style="margin-top:14px">{{ session('contact_sent') === 'WEB' ? __('site.contact.sent_generic') : __('site.contact.sent', ['ref' => session('contact_sent')]) }}</div>
    @endif
    @if($errors->any())<div class="note" role="alert" style="margin-top:14px">{{ __('site.errors.check') }}</div>@endif
    <form method="post" action="/contact" novalidate style="margin-top:18px">
      @csrf
      <div class="hp" aria-hidden="true"><label for="c-website">Website</label><input id="c-website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
      <div class="form-grid">
        <div class="field">
          <label for="c-name">{{ __('site.contact.name') }}</label>
          <input id="c-name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="e-name" @enderror>
          @error('name')<p class="err" id="e-name">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="c-email">{{ __('site.contact.email_field') }}</label>
          <input id="c-email" type="email" name="email" value="{{ old('email') }}" required maxlength="190" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="e-email" @enderror>
          @error('email')<p class="err" id="e-email">{{ $message }}</p>@enderror
        </div>
        <div class="field">
          <label for="c-phone">{{ __('site.contact.phone_field') }}</label>
          <input id="c-phone" type="tel" name="phone" value="{{ old('phone') }}" maxlength="32" autocomplete="tel">
        </div>
        <div class="field">
          <label for="c-topic">{{ __('site.contact.topic') }}</label>
          <select id="c-topic" name="topic">
            @foreach(__('site.contact.topics') as $k => $label)<option value="{{ $k }}" @selected(old('topic', $topic) === $k)>{{ $label }}</option>@endforeach
          </select>
        </div>
        <div class="field full">
          <label for="c-message">{{ __('site.contact.message') }}</label>
          <textarea id="c-message" name="message" required minlength="20" maxlength="5000" aria-describedby="h-message @error('message') e-message @enderror">{{ old('message') }}</textarea>
          <p class="hint" id="h-message">{{ __('site.contact.message_hint') }}</p>
          @error('message')<p class="err" id="e-message">{{ $message }}</p>@enderror
        </div>
      </div>
      <button class="btn btn-gold" type="submit" style="margin-top:18px">{{ __('site.contact.send') }} @include('public.partials.i', ['n' => 'arrow'])</button>
    </form>
  </div>
  <aside class="card">
    <h2 style="font-size:20px">{{ __('site.contact.direct') }}</h2>
    @if($contacts['email'] || $contacts['phone'] || $contacts['whatsapp'])
      <ul class="contact-list" style="margin-top:14px">
        @if($contacts['email'])<li><span class="ico">@include('public.partials.i', ['n' => 'mail'])</span><span><small style="display:block;color:var(--muted)">{{ __('site.contact.email') }}</small><a class="link" href="mailto:{{ $contacts['email'] }}">{{ $contacts['email'] }}</a></span></li>@endif
        @if($contacts['phone'])<li><span class="ico">@include('public.partials.i', ['n' => 'phone'])</span><span><small style="display:block;color:var(--muted)">{{ __('site.contact.phone') }}</small><a class="link" href="tel:{{ preg_replace('/[^+\d]/', '', $contacts['phone']) }}">{{ $contacts['phone'] }}</a></span></li>@endif
        @if($contacts['whatsapp'] && $contacts['whatsapp_url'])<li><span class="ico">@include('public.partials.i', ['n' => 'chat'])</span><span><small style="display:block;color:var(--muted)">{{ __('site.contact.whatsapp') }}</small><a class="link" href="{{ $contacts['whatsapp_url'] }}" rel="noopener">{{ $contacts['whatsapp'] }}</a></span></li>@endif
      </ul>
    @else
      <p style="margin-top:10px">{{ __('site.contact.no_direct') }}</p>
    @endif
    <p style="margin-top:20px"><a class="link" href="/faq">{{ __('site.footer.faq') }} @include('public.partials.i', ['n' => 'arrow'])</a></p>
  </aside>
</div></section>
@endsection
