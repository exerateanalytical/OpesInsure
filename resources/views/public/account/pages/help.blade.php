{{-- S11 /account/help: customer, commercial agent and claims-officer guides (the agent / officer tabs are shown by portal.js to those users only). --}}
@php
  $offered = \App\Application\Help\HelpGuides::SURFACES['account'];
  $requested = request()->query('guide');
  $guideKey = in_array($requested, $offered, true) ? $requested : 'customer';
  $guide = \App\Application\Help\HelpGuides::load($guideKey);
  $q = mb_substr(trim((string) request()->query('q', '')), 0, 100);
  $sections = \App\Application\Help\HelpGuides::search($guide, $q);
  $tabRole = ['customer' => null, 'commercial_agent' => 'agent', 'claims_handler' => 'officer'];
@endphp
@extends('public.account.layout', ['title' => __('help_guides.title'), 'lede' => $guide['title'], 'crumbs' => [[__('help_guides.title'), null]], 'active' => 'help'])
@push('head')@include('help.styles')@endpush
@section('content')
  <nav class="opes-help-tabs" aria-label="{{ __('help_guides.other_guides') }}">
    @foreach($offered as $g)
      <a href="?guide={{ $g }}" @if($tabRole[$g] && $g !== $guideKey) data-role="{{ $tabRole[$g] }}" hidden @endif @if($g === $guideKey) aria-current="page" @endif>{{ __('help_guides.guides.'.$g) }}</a>
    @endforeach
  </nav>
  <form class="opes-help-bar" method="get" role="search">
    <input type="hidden" name="guide" value="{{ $guideKey }}">
    <input type="search" name="q" value="{{ $q }}" maxlength="100" placeholder="{{ __('help_guides.search_placeholder') }}" aria-label="{{ __('help_guides.search') }}" data-help-search>
    <a href="{{ \App\Application\Help\HelpGuides::printUrl($guideKey) }}" target="_blank" rel="noopener">{{ __('help_guides.print_version') }}</a>
  </form>
  @include('help.guide-body', ['guide' => $guide, 'sections' => $sections])
@endsection
@push('scripts')
<script>
(function () {
  var box = document.querySelector('[data-help-search]');
  if (!box) return;
  box.addEventListener('input', function () {
    var q = box.value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').split(/\s+/).filter(Boolean);
    document.querySelectorAll('[data-help-section]').forEach(function (s) {
      var t = s.textContent.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      s.hidden = !q.every(function (w) { return t.indexOf(w) !== -1; });
    });
  });
})();
</script>
@endpush
