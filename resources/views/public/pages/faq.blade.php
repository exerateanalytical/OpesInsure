@extends('public.layout')
@section('title', __('site.faq.title').' — OpesInsure')
@section('description', __('site.faq.lede'))
@push('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect(__('site.faq.items'))->map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]])->all()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush
@section('content')
@include('public.partials.page-hero', ['title' => __('site.faq.title'), 'lede' => __('site.faq.lede')])
<section><div class="wrap prose">
  @foreach(__('site.faq.items') as $i => [$q, $a])
    <details class="faq" @if($i === 0) open @endif><summary>{{ $q }}</summary><p>{{ $a }}</p></details>
  @endforeach
  <p style="margin-top:26px"><a class="link" href="/contact">{{ __('site.common.contact_support') }} @include('public.partials.i', ['n' => 'arrow'])</a></p>
</div></section>
@endsection
