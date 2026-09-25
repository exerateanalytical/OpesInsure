{{-- Shared letterhead legal footer lines (registered address, RCCM, NIU, licence reference): only values an admin entered. --}}
@if(!empty(($letterhead ?? null)['footer_lines']))
  <div class="lh-footer" style="font-size:7.5px;color:#5b6b7d">{{ ($letterhead['issuer']['name'] ?? '') }} · {{ implode(' · ', $letterhead['footer_lines']) }}</div>
@endif
