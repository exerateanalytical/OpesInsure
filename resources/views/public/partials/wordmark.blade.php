@php
  $dir = \App\Interfaces\Http\Controllers\Web\PublicProviderDirectory::class;
  $sub = $p['kind'] === 'broker' ? __('site.providers.broker') : ($p['branch'] === 'LIFE' ? __('site.providers.life') : __('site.providers.iard'));
@endphp
{{-- Typographic badge: registered name only, never a third-party logo. --}}
<div class="wordmark t{{ $dir::tone($p['short']) }}">
  <span class="mono" aria-hidden="true">{{ $dir::initials($p['short']) }}</span>
  <span class="nm"><b title="{{ $p['name'] }}">{{ ($full ?? false) ? $p['name'] : $p['short'] }}</b><small>{{ $sub }}@if(!empty($p['city'])) · {{ $p['city'] }}@endif</small></span>
</div>
