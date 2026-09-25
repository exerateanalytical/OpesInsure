{{-- Shared letterhead (header): issuer logo + name, co-branding row, text wordmark fallback (never a broken image).
     Data: App\Application\Documents\Letterhead\LetterheadResolver::forDocument(). $letterhead may be null. --}}
@php
  $lh = $letterhead ?? null;
  $lhName = $lh['issuer']['name'] ?? ($lhFallbackName ?? 'OpesInsure');
  $lhColor = $lh['color'] ?? ($lhDefaultColor ?? '#0b2a4a');
  $lhT = $L ?? fn ($fr, $en) => $fr.' / '.$en;
@endphp
<div class="lh">
  @if(!empty($lh['issuer']['header']))
    <img class="lh-header" src="{{ $lh['issuer']['header'] }}" alt="{{ $lhName }}" style="max-width:100%;max-height:{{ $lhHeaderHeight ?? 44 }}px">
  @else
    <table style="border-collapse:collapse"><tr>
      @if(!empty($lh['issuer']['logo']))<td style="padding:0 8px 0 0;vertical-align:middle"><img class="lh-logo" src="{{ $lh['issuer']['logo'] }}" alt="{{ $lhName }}" style="max-height:{{ $lhLogoHeight ?? 34 }}px;max-width:150px"></td>@endif
      <td style="padding:0;vertical-align:middle"><div class="brand lh-wordmark" style="color:{{ $lhColor }};font-weight:bold">{{ $lhName }}</div></td>
    </tr></table>
  @endif
  @if(!empty($lh['cobrand']))
    <div class="lh-cobrand" style="font-size:8px;color:#5b6b7d;margin-top:1px">
      @if(!empty($lh['cobrand']['logo']))<img src="{{ $lh['cobrand']['logo'] }}" alt="{{ $lh['cobrand']['name'] }}" style="max-height:14px;max-width:70px;vertical-align:middle"> @endif
      @if($lh['cobrand']['role'] === 'INSURER'){{ $lhT("Pour le compte de l'assureur", 'On behalf of insurer') }}@else{{ $lhT("Intermédiaire d'assurance", 'Insurance intermediary') }}@endif:
      <strong>{{ $lh['cobrand']['name'] }}</strong>@if(!empty($lh['cobrand']['licence'])) ({{ $lh['cobrand']['licence'] }})@endif
    </div>
  @endif
</div>
