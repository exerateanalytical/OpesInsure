@php
  $dir = \App\Interfaces\Http\Controllers\Web\PublicProviderDirectory::class;
  $sub = $p['kind'] === 'broker' ? __('site.providers.broker') : ($p['branch'] === 'LIFE' ? __('site.providers.life') : __('site.providers.iard'));
@endphp
{{-- Typographic badge; an admin-uploaded, authorized logo (public-display) replaces the initials when present. Never scraped. --}}
<div class="wordmark t{{ $dir::tone($p['short']) }}">
  @if(!empty($p['logo_url']))<span class="mono" aria-hidden="true" style="background:#fff;padding:2px"><img src="{{ $p['logo_url'] }}" alt="" loading="lazy" style="max-width:100%;max-height:100%;object-fit:contain" onerror="this.parentNode.textContent='{{ $dir::initials($p['short']) }}'"></span>@else<span class="mono" aria-hidden="true">{{ $dir::initials($p['short']) }}</span>@endif
  <span class="nm"><b title="{{ $p['name'] }}">{{ ($full ?? false) ? $p['name'] : $p['short'] }}</b><small>{{ $sub }}@if(!empty($p['city'])) · {{ $p['city'] }}@endif</small></span>
</div>
