{{-- Institutional directory details (official insurers only; nothing rendered when unknown). --}}
@if($p['kind'] === 'insurer' && ($p['verification_status'] || $p['phone'] || $p['website'] || $p['branch_count']))
<div class="pd">
  @if($p['verification_status'])<span class="pd-badge pd-{{ strtolower($p['verification_status']) === 'verified' ? 'ok' : 'part' }}">{{ $p['verification_label'][app()->getLocale() === 'fr' ? 'fr' : 'en'] ?? $p['verification_status'] }}</span>@endif
  @if($p['city'])<span>{{ __('site.providers_page.hq', ['city' => $p['city']]) }}</span>@endif
  <span>{{ trans_choice('site.providers_page.branches', $p['branch_count'], ['count' => $p['branch_count']]) }}</span>
  @if($p['phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $p['phone']) }}">{{ $p['phone'] }}</a>@endif
  @if($p['email'])<a href="mailto:{{ $p['email'] }}">{{ $p['email'] }}</a>@endif
  @if($p['website'])<a href="{{ $p['website'] }}" rel="noopener nofollow" target="_blank">{{ $p['website_host'] }}</a>@endif
</div>
@endif
