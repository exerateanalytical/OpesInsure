{{-- /account/accept/{token} — the customer's own web acceptance of an application an agent/broker prepared
     (ProposalAcceptancePageController, ProposalAcceptanceLinks). States: verify (OTP to the proposal's phone) → review
     (questions, declarations, contract terms) → done; or invalid / expired / used / accepted / closed / blocked. --}}
@extends('public.layout')
@section('title', __('acceptance.title').' — OpesInsure')
@section('description', __('acceptance.lede'))
@section('content')
@include('public.partials.page-hero', ['title' => __('acceptance.title'), 'lede' => __('acceptance.lede'), 'img' => 'hero-home', 'points' => []])
<div class="ip">
<section><div class="wrap two-col">
  <div>
    <div class="card form-card" data-acceptance="{{ $state }}">
      @if(session('acceptance_status'))<div class="note ok" role="status">{{ session('acceptance_status') }}</div>@endif
      @if($errors->any())<div class="note" role="alert">{{ $errors->first() }}</div>@endif

      @if($state === 'done')
        <h2 style="font-size:20px">{{ __('acceptance.done_t') }}</h2>
        <p style="margin-top:10px">{{ __('acceptance.done_d', ['number' => $done['number'] ?? '']) }}</p>
        @if(!empty($done['pending']))<div class="note" role="status" style="margin-top:12px">{{ __('acceptance.done_pending') }} {{ $done['pending'] }}</div>@endif
        <p style="margin-top:12px">{{ __('acceptance.done_next') }}</p>
        <p style="margin-top:14px"><a class="btn btn-gold" href="/download">{{ __('acceptance.get_app') }}</a></p>
      @elseif(in_array($state, ['invalid', 'expired', 'used', 'accepted', 'closed', 'blocked'], true))
        <h2 style="font-size:20px">{{ __('acceptance.state.'.$state.'_t') }}</h2>
        <p style="margin-top:10px">{{ __('acceptance.state.'.$state.'_d') }}</p>
      @elseif($state === 'verify')
        <h2 style="font-size:20px">{{ __('acceptance.verify_t') }}</h2>
        <p style="margin-top:10px">{{ __('acceptance.verify_d', ['phone' => $phone]) }}</p>
        @if(! $codeSent)
          <form method="post" action="{{ request()->fullUrl() }}" style="margin-top:16px">
            @csrf <input type="hidden" name="intent" value="send_code">
            <button class="btn btn-gold" type="submit">{{ __('acceptance.send_code') }}</button>
          </form>
        @else
          <form method="post" action="{{ request()->fullUrl() }}" novalidate style="margin-top:16px">
            @csrf <input type="hidden" name="intent" value="verify">
            <div class="field full">
              <label for="a-code">{{ __('acceptance.code') }}</label>
              <input id="a-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
              @error('code')<p class="err">{{ $message }}</p>@enderror
            </div>
            <button class="btn btn-gold" type="submit" style="margin-top:14px">{{ __('acceptance.verify') }}</button>
          </form>
          <form method="post" action="{{ request()->fullUrl() }}" style="margin-top:10px">
            @csrf <input type="hidden" name="intent" value="send_code">
            <button class="btn" type="submit">{{ __('acceptance.resend') }}</button>
          </form>
        @endif
      @else {{-- review --}}
        <h2 style="font-size:20px">{{ __('acceptance.review_t') }}</h2>
        <p style="margin-top:10px">{{ __('acceptance.review_d') }}</p>
        <form method="post" action="{{ request()->fullUrl() }}" novalidate style="margin-top:16px">
          @csrf <input type="hidden" name="intent" value="accept">
          @if(count($questions))
            <h3 style="font-size:17px">{{ __('acceptance.questions_t') }}</h3>
            <div class="form-grid" style="margin-top:10px">
              @foreach($questions as $q)
                <div class="field full">
                  <label for="q-{{ $q['code'] }}">{{ $q['label'] }}@if($q['required']) *@endif</label>
                  @if($q['type'] === 'boolean')
                    <div role="radiogroup" id="q-{{ $q['code'] }}" style="display:flex;gap:18px">
                      <label class="check"><input type="radio" name="answers[{{ $q['code'] }}]" value="true" @checked($q['value'] === 'true')> <span>{{ __('acceptance.yes') }}</span></label>
                      <label class="check"><input type="radio" name="answers[{{ $q['code'] }}]" value="false" @checked($q['value'] === 'false')> <span>{{ __('acceptance.no') }}</span></label>
                    </div>
                  @elseif(count($q['options']))
                    <select id="q-{{ $q['code'] }}" name="answers[{{ $q['code'] }}]">
                      <option value="">{{ __('acceptance.choose') }}</option>
                      @foreach($q['options'] as $o)<option value="{{ $o['value'] }}" @selected($q['value'] === $o['value'])>{{ $o['label'] }}</option>@endforeach
                    </select>
                  @else
                    <input id="q-{{ $q['code'] }}" name="answers[{{ $q['code'] }}]" value="{{ $q['value'] }}" type="{{ $q['type'] === 'number' ? 'number' : ($q['type'] === 'date' ? 'date' : 'text') }}">
                  @endif
                </div>
              @endforeach
            </div>
          @endif
          @if($attest)
            <label class="check" style="margin-top:16px"><input type="checkbox" name="attest" value="1" @checked(old('attest'))> <span>{{ $attest }}</span></label>
          @endif
          <div class="note" style="margin-top:16px">
            <b>{{ __('acceptance.terms_t') }}</b>
            <p style="margin:6px 0 0">{{ __('acceptance.terms_d', ['total' => $summary['total'] ?? '']) }}</p>
          </div>
          <label class="check" style="margin-top:12px"><input type="checkbox" name="terms" value="1" @checked(old('terms'))> <span>{{ $terms }}</span></label>
          <p class="hint" style="margin-top:10px">{{ __('acceptance.account_note') }}</p>
          <button class="btn btn-gold" type="submit" style="margin-top:16px">{{ __('acceptance.accept') }}</button>
        </form>
      @endif
    </div>
  </div>
  <aside class="card">
    @if($summary)
      <h2 style="font-size:20px">{{ __('acceptance.summary_t') }}</h2>
      <dl style="margin:12px 0 0;display:grid;grid-template-columns:auto 1fr;gap:6px 14px">
        <dt>{{ __('acceptance.application') }}</dt><dd><b>{{ $summary['number'] }}</b></dd>
        @if($summary['customer'])<dt>{{ __('acceptance.insured') }}</dt><dd>{{ $summary['customer'] }}</dd>@endif
        @if($summary['carrier'])<dt>{{ __('acceptance.insurer') }}</dt><dd>{{ $summary['carrier'] }}</dd>@endif
        @if($summary['product'])<dt>{{ __('acceptance.product') }}</dt><dd>{{ $summary['product'] }}</dd>@endif
        @foreach($summary['rows'] as $r)<dt>{{ $r[0] }}</dt><dd>{{ ($summary['money'])($r[1]) }}</dd>@endforeach
        <dt><b>{{ __('acceptance.total') }}</b></dt><dd><b>{{ $summary['total'] }}</b></dd>
      </dl>
      @if(count($summary['coverages']))
        <h3 style="font-size:16px;margin-top:16px">{{ __('acceptance.coverages') }}</h3>
        <ul style="padding-left:20px;margin:8px 0 0">@foreach($summary['coverages'] as $c)<li style="margin-bottom:4px">{{ $c['name'] }}@if($c['limit']) — {{ $c['limit'] }}@endif @if($c['optional'])<small>({{ __('acceptance.optional') }})</small>@endif</li>@endforeach</ul>
      @endif
    @else
      <h2 style="font-size:20px">{{ __('acceptance.help_t') }}</h2>
    @endif
    <p style="margin-top:14px;color:var(--muted)">{{ __('acceptance.safety') }}</p>
  </aside>
</div></section>
</div>
@endsection
