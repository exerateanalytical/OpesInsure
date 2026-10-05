{{-- Brokerage confirmation of an agent application (single-use link emailed to the brokerage's administrators). --}}
@extends('public.layout')
@php $B = __('partner_apply.brokerage'); @endphp
@section('title', $B['title'].' — OpesInsure')
@section('description', $B['title'])
@section('content')
@include('public.partials.page-hero', ['title' => $B['title'], 'lede' => $a->reference, 'img' => 'about-building'])
<div class="ip">
<section><div class="wrap">
  <div class="card form-card">
    <p>{{ __('partner_apply.brokerage.body', ['agent' => $a->applicant_name, 'reference' => $a->reference]) }}</p>
    <form method="post" action="/partners/apply/brokerage/{{ $token }}" style="margin-top:14px;display:flex;gap:12px;flex-wrap:wrap">
      @csrf
      <button class="btn btn-gold" type="submit" name="decision" value="confirm">{{ $B['confirm'] }}</button>
      <button class="btn" type="submit" name="decision" value="decline">{{ $B['decline'] }}</button>
    </form>
  </div>
</div></section>
</div>
@endsection
