{{-- "Claim this organisation" (/organisations/claim/{insurer|broker}/{id}). OrganisationClaimPageController → OrganisationClaimService. --}}
@extends('public.layout')
@php $C = __('org_claim'); $title = __('org_claim.title', ['name' => $inst['name']]); @endphp
@section('title', $title.' — OpesInsure')
@section('description', $C['lede'])
@section('content')
@include('public.partials.page-hero', ['title' => $title, 'lede' => $C['lede'], 'img' => 'about-building'])
<div class="ip">
<section><div class="wrap">
  <div class="card">
    <ol style="padding-left:20px;margin:0">@foreach($C['how'] as $s)<li style="margin-bottom:6px">{{ $s }}</li>@endforeach</ol>
  </div>
  <div class="card form-card" style="margin-top:20px">
    @if(session('org_claim_sent'))<div class="note ok" role="status">{{ $C['sent'] }}</div>@endif
    @if($lock)
      <div class="note" role="status">{{ $lock === 'CLAIMED' ? $C['locked_claimed'] : $C['locked_pending'] }}</div>
      <h2 style="font-size:18px;margin-top:14px">{{ $C['dispute_title'] }}</h2>
      <p>{{ $C['dispute_body'] }}</p>
    @elseif($masked)
      <p class="note">{{ __('org_claim.official_code', ['dest' => $masked]) }}</p>
    @else
      <p class="note">{{ $C['manual'] }}</p>
    @endif
    @if($errors->any())<div class="note" role="alert" style="margin-top:14px">{{ __('site.errors.check') }}@foreach($errors->all() as $m)<br>{{ $m }}@endforeach</div>@endif
    <form method="post" action="/organisations/claim/{{ $kind }}/{{ $id }}" enctype="multipart/form-data" novalidate style="margin-top:16px">
      @csrf
      <div class="hp" aria-hidden="true"><label for="oc-website">Website</label><input id="oc-website" type="text" name="website" tabindex="-1" autocomplete="off"></div>
      @if($lock)<input type="hidden" name="dispute" value="1">@endif
      <div class="form-grid">
        @foreach(['claimant_name' => 'text', 'claimant_position' => 'text', 'claimant_email' => 'email', 'claimant_phone' => 'tel'] as $f => $t)
          <div class="field"><label for="oc-{{ $f }}">{{ $C['fields'][$f] }} @if($f !== 'claimant_phone')<i>*</i>@endif</label>
            <input id="oc-{{ $f }}" type="{{ $t }}" name="{{ $f }}" value="{{ old($f) }}" maxlength="190">@error($f)<p class="err">{{ $message }}</p>@enderror</div>
        @endforeach
        <div class="field full"><label for="oc-statement">{{ $C['fields']['statement'] }}</label>
          <textarea id="oc-statement" name="statement" maxlength="3000" style="min-height:80px">{{ old('statement') }}</textarea></div>
        @foreach(['doc_authority', 'doc_id'] as $f)
          <div class="field"><label for="oc-{{ $f }}">{{ $C['fields'][$f] }} <i>*</i></label>
            <input id="oc-{{ $f }}" type="file" name="{{ $f }}" accept=".pdf,.jpg,.jpeg,.png">@error($f)<p class="err">{{ $message }}</p>@enderror</div>
        @endforeach
        <p class="hint full">{{ __('partner_apply.hints.docs') }}</p>
        <div class="full"><label class="check"><input type="checkbox" name="consent" value="1" @checked(old('consent'))> <span>{{ $C['fields']['consent'] }}</span></label>@error('consent')<p class="err">{{ $message }}</p>@enderror</div>
      </div>
      <button class="btn btn-gold" type="submit" style="margin-top:16px">{{ $lock ? $C['dispute_submit'] : $C['submit'] }}</button>
    </form>
  </div>
</div></section>
</div>
@endsection
