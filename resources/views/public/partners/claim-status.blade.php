{{-- Claimant's private status page (/organisations/claim/status/{token}). --}}
@extends('public.layout')
@php $C = __('org_claim'); $title = __('org_claim.status.title', ['reference' => $c->reference, 'name' => $c->institution_name]); @endphp
@section('title', $title.' — OpesInsure')
@section('description', $C['lede'])
@section('content')
@include('public.partials.page-hero', ['title' => $title, 'lede' => $C['lede'], 'img' => 'about-building'])
<div class="ip">
<section><div class="wrap">
  <div class="card">
    <p><b>{{ __('partner_apply.admin.columns.status') }}:</b> {{ $C['statuses'][$c->status] ?? $c->status }}</p>
    @if($c->verification_mode === 'MANUAL' && ! $c->is_dispute)<p>{{ $C['status']['manual_note'] }}</p>@endif
  </div>
  @include('public.partners._intake', ['r' => $c, 'base' => '/organisations/claim/status/'.$token, 'canResend' => true,
    'codeHelp' => __('org_claim.status.code_help', ['dest' => (string) $c->official_destination_masked])])
</div></section>
</div>
@endsection
