@php
  $labels = [
    'valid' => ['Valid — insured', 'Valide — assuré', '#07855B', 'circle-check'],
    'expired' => ['Expired', 'Expirée', '#764B00', 'clock'],
    'not_yet_active' => ['Not yet active', 'Pas encore active', '#1256B8', 'clock'],
    'revoked' => ['Revoked', 'Révoquée', '#98272E', 'circle-x'],
    'superseded' => ['Superseded — a newer document exists', 'Remplacée par une version plus récente', '#764B00', 'triangle-alert'],
    'replaced' => ['Replaced', 'Remplacée', '#764B00', 'triangle-alert'],
    'suspended' => ['Suspended', 'Suspendue', '#98272E', 'circle-x'],
    'cancelled' => ['Cancelled', 'Résiliée', '#98272E', 'circle-x'],
    'invalid' => ['Not valid', 'Non valide', '#98272E', 'circle-x'],
    'not_found' => ['Certificate not found', 'Attestation introuvable', '#566776', 'circle-help'],
  ];
  $r = $result['result'] ?? 'not_found';
  [$en, $fr, $color, $icon] = $labels[$r] ?? $labels['not_found'];
  $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->timezone(config('app.timezone'))->format('d/m/Y') : '—';
@endphp
@extends('public.layout')
@section('title', 'OpesInsure — Verify a certificate / Vérifier une attestation')
@section('content')
<section class="ip-hero" aria-labelledby="page-title">
  <div class="ip-photo" aria-hidden="true"><picture><source type="image/webp" srcset="/landing/img/desk/hero-motor.webp"><img src="/landing/img/desk/hero-motor.jpg" alt="" fetchpriority="high"></picture></div>
  <div class="wrap-x"><div class="ip-copy">
    <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home / Accueil</a>@include('public.partials.i', ['n' => 'chev'])<span aria-current="page">Verify / Vérifier</span></nav>
    <p class="d-eyebrow">Certificate verification · Vérification d'attestation</p>
    <h1 id="page-title">Is this cover valid?<br><span class="ip-sub">Cette assurance est-elle valide&nbsp;?</span></h1>
  </div></div>
</section>
<div class="ip">
<section><div class="wrap" style="max-width:720px">
  @if($result === null)
    <div class="card">
      <p>Scan the QR code printed on an OpesInsure certificate to check it.<br>
      <span style="color:var(--muted)">Scannez le QR code imprimé sur une attestation OpesInsure pour la vérifier.</span></p>
    </div>
  @else
    <div class="card" role="status" data-result="{{ $r }}" style="border-left:6px solid {{ $color }}">
      <div style="display:flex;gap:14px;align-items:center">
        <div style="width:2.25rem;height:2.25rem;flex:none;color:{{ $color }}">{{ svg('lucide-'.$icon, '', ['aria-hidden' => 'true', 'focusable' => 'false', 'style' => 'width:100%;height:100%']) }}</div>
        <div>
          <div style="font-size:1.35rem;font-weight:800;color:{{ $color }}">{{ $en }}</div>
          <div style="color:var(--muted)">{{ $fr }}</div>
        </div>
      </div>
      @if($r !== 'not_found')
      <table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:.95rem">
        @if(!empty($result['document']))
        <tr><td style="padding:8px 0;color:var(--muted)">Document</td><td style="text-align:right;font-weight:700">{{ $result['document']['title_fr'] }} / {{ $result['document']['title_en'] }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Number / Numéro</td><td style="text-align:right">{{ $result['document']['document_number'] ?? '—' }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Status / Statut</td><td style="text-align:right">{{ $result['document']['status'] }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Issuer / Émetteur</td><td style="text-align:right">{{ $result['document']['issuer_name'] ?? '—' }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Issued / Émis</td><td style="text-align:right">{{ $fmt($result['document']['issued_at']) }}</td></tr>
        <tr><td style="padding:8px 0;color:var(--muted)">Policy / Police</td><td style="text-align:right">{{ $result['document']['policy_reference'] ?? '—' }}</td></tr>
        @if($result['document']['vehicle'])<tr><td style="padding:8px 0;color:var(--muted)">Vehicle / Véhicule</td><td style="text-align:right">{{ $result['document']['vehicle'] }}</td></tr>@endif
        @if($result['document']['replaced_by'])<tr><td style="padding:8px 0;color:var(--muted)">Replaced by / Remplacé par</td><td style="text-align:right">{{ $result['document']['replaced_by'] }}</td></tr>@endif
        <tr><td style="padding:8px 0;color:var(--muted)">SHA-256</td><td style="text-align:right;font-size:.7rem;word-break:break-all">{{ $result['document']['sha256'] }}</td></tr>
        @else
        <tr><td style="padding:8px 0;color:var(--muted)">Certificate / Attestation</td><td style="text-align:right;font-weight:700">{{ $result['reference'] }}</td></tr>
        @endif
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
</div>
@endsection
