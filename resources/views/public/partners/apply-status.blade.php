{{-- Applicant's private status page (/partners/apply/status/{token}). --}}
@extends('public.layout')
@php $P = __('partner_apply'); $title = __('partner_apply.status.title', ['reference' => $a->reference]); @endphp
@section('title', $title.' — OpesInsure')
@section('description', $P['lede'])
@section('content')
@include('public.partials.page-hero', ['title' => $title, 'lede' => $a->legal_name, 'img' => 'about-building'])
<div class="ip">
<section><div class="wrap">
  <div class="card">
    <p><b>{{ $P['status']['type'] }}:</b> {{ $P['types'][$a->type] ?? $a->type }}</p>
    <p><b>{{ __('partner_apply.admin.columns.status') }}:</b> {{ $P['statuses'][$a->status] ?? $a->status }}</p>
    <p><b>{{ $P['status']['submitted_on'] }}:</b> {{ $a->created_at->timezone('Africa/Douala')->format('d/m/Y H:i') }}</p>
    @if($a->type === 'AGENT' && $a->brokerage_tenant_id && ! in_array($a->status, ['UNVERIFIED', 'APPROVED', 'REJECTED'], true))
      <p>{{ $a->brokerage_confirmed_at ? $P['status']['brokerage_confirmed'] : ($a->brokerage_declined_at ? $P['status']['brokerage_declined'] : $P['status']['brokerage_pending']) }}</p>
    @endif
  </div>
  @include('public.partners._intake', ['r' => $a, 'base' => '/partners/apply/status/'.$token, 'canResend' => true,
    'codeHelp' => $a->verification_channel === 'SMS' ? $P['status']['code_help_sms'] : $P['status']['code_help_email']])
</div></section>
</div>
@endsection
