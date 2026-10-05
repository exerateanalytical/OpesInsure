{{-- Partner self-service application (/partners/apply). PartnerApplicationPageController → PartnerApplicationService. --}}
@extends('public.layout')
@php $P = __('partner_apply'); @endphp
@section('title', $P['title'].' — OpesInsure')
@section('description', $P['lede'])
@section('content')
@include('public.partials.page-hero', ['title' => $P['title'], 'lede' => $P['lede'], 'img' => 'about-building'])
<div class="ip">
<section><div class="wrap">
  <div class="card form-card">
    @if(session('partner_apply_sent'))<div class="note ok" role="status">{{ $P['sent'] }}</div>@endif
    @if(session('partner_apply_brokerage'))<div class="note ok" role="status">{{ session('partner_apply_brokerage') === 'confirm' ? $P['brokerage']['done_confirm'] : $P['brokerage']['done_decline'] }}</div>@endif
    <p class="note">{{ $P['claim_hint'] }} <a class="link" href="/providers">{{ __('site.providers.view_all') }}</a></p>
    @if($errors->any())<div class="note" role="alert" style="margin-top:14px">{{ __('site.errors.check') }}@foreach($errors->all() as $m)<br>{{ $m }}@endforeach</div>@endif
    <form method="post" action="/partners/apply" enctype="multipart/form-data" novalidate style="margin-top:16px">
      @csrf
      <div class="hp" aria-hidden="true"><label for="pa-website">Website</label><input id="pa-website" type="text" name="website" tabindex="-1" autocomplete="off"></div>

      <h2 style="font-size:18px">{{ $P['sections']['type'] }}</h2>
      <div class="form-grid">
        <div class="field full">
          @foreach($P['types'] as $k => $label)
            <label class="check" style="margin-right:18px"><input type="radio" name="type" value="{{ $k }}" @checked(old('type', $type) === $k)> <span>{{ $label }}</span></label>
          @endforeach
        </div>
      </div>

      <h2 style="font-size:18px;margin-top:20px">{{ $P['sections']['organisation'] }}</h2>
      <div class="form-grid">
        @foreach(['legal_name' => true, 'trade_name' => false, 'rccm' => false, 'niu' => true, 'city' => true, 'address' => false, 'org_phone' => false, 'org_email' => false] as $f => $req)
          <div class="field">
            <label for="pa-{{ $f }}">{{ $P['fields'][$f] }} @if($req)<i>*</i>@endif</label>
            <input id="pa-{{ $f }}" name="{{ $f }}" value="{{ old($f) }}" maxlength="190" @if($f === 'org_email') type="email" @elseif($f === 'org_phone') type="tel" @endif @error($f) aria-invalid="true" @enderror>
            @if(isset($P['hints'][$f]))<p class="hint">{{ $P['hints'][$f] }}</p>@endif
            @error($f)<p class="err">{{ $message }}</p>@enderror
          </div>
        @endforeach
      </div>

      <h2 style="font-size:18px;margin-top:20px">{{ $P['sections']['licence'] }}</h2>
      <p class="hint">{{ $P['hints']['licence'] }}</p>
      <div class="form-grid">
        <div class="field"><label for="pa-licence_number">{{ $P['fields']['licence_number'] }}</label>
          <input id="pa-licence_number" name="licence_number" value="{{ old('licence_number') }}" maxlength="64">@error('licence_number')<p class="err">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="pa-licence_expires_on">{{ $P['fields']['licence_expires_on'] }}</label>
          <input id="pa-licence_expires_on" type="date" name="licence_expires_on" value="{{ old('licence_expires_on') }}">@error('licence_expires_on')<p class="err">{{ $message }}</p>@enderror</div>
      </div>

      <h2 style="font-size:18px;margin-top:20px">{{ $P['sections']['agent'] }} ({{ $P['types']['AGENT'] }})</h2>
      <p class="hint">{{ $P['hints']['agent_brokerage'] }}</p>
      <div class="form-grid">
        <div class="field"><label for="pa-brokerage">{{ $P['fields']['brokerage'] }}</label>
          <select id="pa-brokerage" name="brokerage_tenant_id"><option value="">{{ $P['fields']['brokerage_none'] }}</option>
            @foreach($brokerages as $id => $name)<option value="{{ $id }}" @selected(old('brokerage_tenant_id') === $id)>{{ $name }}</option>@endforeach
          </select>@error('brokerage_tenant_id')<p class="err">{{ $message }}</p>@enderror</div>
        <div class="field"><label class="check"><input type="checkbox" name="independent" value="1" @checked(old('independent'))> <span>{{ $P['fields']['independent'] }}</span></label></div>
      </div>

      <h2 style="font-size:18px;margin-top:20px">{{ $P['sections']['contact'] }}</h2>
      <div class="form-grid">
        <div class="field"><label for="pa-applicant_name">{{ $P['fields']['applicant_name'] }} <i>*</i></label>
          <input id="pa-applicant_name" name="applicant_name" value="{{ old('applicant_name') }}" maxlength="160" autocomplete="name">@error('applicant_name')<p class="err">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="pa-applicant_email">{{ $P['fields']['applicant_email'] }} <i>*</i></label>
          <input id="pa-applicant_email" type="email" name="applicant_email" value="{{ old('applicant_email') }}" maxlength="190" autocomplete="email">@error('applicant_email')<p class="err">{{ $message }}</p>@enderror</div>
        <div class="field"><label for="pa-applicant_phone">{{ $P['fields']['applicant_phone'] }}</label>
          <input id="pa-applicant_phone" type="tel" name="applicant_phone" value="{{ old('applicant_phone') }}" maxlength="32" autocomplete="tel">
          <p class="hint">{{ $P['hints']['phone_otp'] }}</p>@error('applicant_phone')<p class="err">{{ $message }}</p>@enderror</div>
      </div>

      <h2 style="font-size:18px;margin-top:20px">{{ $P['sections']['documents'] }}</h2>
      <p class="hint">{{ $P['hints']['docs'] }}</p>
      <div class="form-grid">
        @foreach(['doc_licence', 'doc_rccm', 'doc_id'] as $f)
          <div class="field"><label for="pa-{{ $f }}">{{ $P['fields'][$f] }}</label>
            <input id="pa-{{ $f }}" type="file" name="{{ $f }}" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">@error($f)<p class="err">{{ $message }}</p>@enderror</div>
        @endforeach
        <div class="full"><label class="check"><input type="checkbox" name="consent" value="1" @checked(old('consent'))> <span>{{ $P['fields']['consent'] }}</span></label>@error('consent')<p class="err">{{ $message }}</p>@enderror</div>
      </div>
      <button class="btn btn-gold" type="submit" style="margin-top:16px">{{ $P['submit'] }} @include('public.partials.i', ['n' => 'arrow'])</button>
    </form>
  </div>
</div></section>
</div>
@endsection
