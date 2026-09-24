@php
  $labels = [
    'valid' => ['Valid — insured', 'Valide — assuré', '#0f766e', '&#10004;'],
    'expired' => ['Expired', 'Expirée', '#b45309', '&#9888;'],
    'not_yet_active' => ['Not yet active', 'Pas encore active', '#155FCC', '&#8987;'],
    'revoked' => ['Revoked', 'Révoquée', '#b91c1c', '&#10006;'],
    'suspended' => ['Suspended', 'Suspendue', '#b91c1c', '&#10006;'],
    'cancelled' => ['Cancelled', 'Résiliée', '#b91c1c', '&#10006;'],
    'invalid' => ['Not valid', 'Non valide', '#b91c1c', '&#10006;'],
    'not_found' => ['Certificate not found', 'Attestation introuvable', '#566776', '?'],
  ];
  $r = $result['result'] ?? 'not_found';
  [$en, $fr, $color, $icon] = $labels[$r] ?? $labels['not_found'];
  $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->timezone(config('app.timezone'))->format('d/m/Y') : '—';
@endphp
<x-public.layout title="OpesInsure — Verify a certificate / Vérifier une attestation">
<div class="hero" style="padding:48px 0 52px"><div class="wrap">
  <span class="eyebrow">Certificate verification · Vérification d'attestation</span>
  <h1 style="max-width:none">Is this cover valid?<br><span style="font-weight:600;opacity:.8">Cette assurance est-elle valide&nbsp;?</span></h1>
</div></div>
<section><div class="wrap" style="max-width:720px">
  @if($result === null)
    <div class="card">
      <p>Scan the QR code printed on an OpesInsure certificate to check it.<br>
      <span style="color:var(--muted)">Scannez le QR code imprimé sur une attestation OpesInsure pour la vérifier.</span></p>
    </div>
  @else
    <div class="card" style="border-left:6px solid {{ $color }}">
      <div style="display:flex;gap:14px;align-items:center">
        <div style="font-size:2rem;color:{{ $color }}" aria-hidden="true">{!! $icon !!}</div>
        <div>
          <div style="font-size:1.35rem;font-weight:800;color:{{ $color }}">{{ $en }}</div>
          <div style="color:var(--muted)">{{ $fr }}</div>
        </div>
      </div>
      @if($r !== 'not_found')
      <table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:.95rem">
        <tr><td style="padding:8px 0;color:var(--muted)">Certificate / Attestation</td><td style="text-align:right;font-weight:700">{{ $result['reference'] }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Insurer / Assureur</td><td style="text-align:right">{{ $result['carrier_name'] ?? '—' }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Class / Branche</td><td style="text-align:right">{{ $result['product_class'] ?? '—' }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">From / Du</td><td style="text-align:right">{{ $fmt($result['coverage_starts_at']) }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">To / Au</td><td style="text-align:right">{{ $fmt($result['coverage_ends_at']) }}</td></tr>
      </table>
      @else
      <p style="margin-top:14px">We could not verify this certificate. Check that you scanned the QR code on the original document, or contact the insurer.<br>
      <span style="color:var(--muted)">Impossible de vérifier cette attestation. Vérifiez que vous avez scanné le QR code du document original, ou contactez l'assureur.</span></p>
      @endif
      <p style="margin-top:16px;font-size:.82rem;color:var(--muted)">Checked / Vérifié : {{ now()->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</p>
    </div>
  @endif
</div></section>
</x-public.layout>
