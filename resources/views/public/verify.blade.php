@php
  // Detailed result (legacy vocabulary) → headline; canonical state (VALID | EXPIRED | REVOKED | REPLACED | …) → banner.
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
  $code = $result['verification_result'] ?? null;
  $tampered = in_array($code, ['HASH_MISMATCH', 'SIGNATURE_INVALID', 'POTENTIAL_TAMPERING'], true);
  $state = ($tampered || $r === 'invalid') ? 'INVALID' : ($result['status'] ?? \App\Application\Documents\Verification\PublicVerificationService::canonicalStatus($r));
  $states = [
    'VALID' => ['VALID', 'VALIDE', 'This document is genuine and in force.', 'Ce document est authentique et en vigueur.'],
    'EXPIRED' => ['EXPIRED', 'EXPIRÉ', 'This document was genuine but its validity period has ended.', 'Ce document était authentique mais sa période de validité est terminée.'],
    'REVOKED' => ['REVOKED', 'RÉVOQUÉ', 'This document has been withdrawn and must not be relied on.', 'Ce document a été retiré et ne doit plus être utilisé.'],
    'REPLACED' => ['REPLACED', 'REMPLACÉ', 'A newer version replaces this document. Ask the holder for the current one.', 'Une version plus récente remplace ce document. Demandez la version en cours au titulaire.'],
    'NOT_YET_ACTIVE' => ['NOT YET ACTIVE', 'PAS ENCORE ACTIF', 'This document is genuine but not yet in force.', 'Ce document est authentique mais pas encore en vigueur.'],
    'INVALID' => ['NOT VALID', 'NON VALIDE', 'This document does not match the registry original. It may have been altered.', 'Ce document ne correspond pas à l\'original du registre. Il a pu être modifié.'],
    'NOT_FOUND' => ['NOT FOUND', 'INTROUVABLE', 'No document matches this code.', 'Aucun document ne correspond à ce code.'],
  ];
  [$stEn, $stFr, $stMsgEn, $stMsgFr] = $states[$state] ?? $states['NOT_FOUND'];
  $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->timezone(config('app.timezone'))->format('d/m/Y') : '—';
  $doc = $result['document'] ?? null;
@endphp
@extends('public.layout')
@section('title', 'OpesInsure — Verify a certificate / Vérifier une attestation')
@section('content')
<style>
  .vf-banner{display:flex;gap:14px;align-items:center;flex-wrap:wrap}
  .vf-state{display:inline-block;padding:.3rem .75rem;border-radius:999px;color:#fff;font-weight:800;letter-spacing:.04em;font-size:.95rem}
  .vf-table{width:100%;border-collapse:collapse;margin-top:18px;font-size:.95rem}
  .vf-table td{padding:8px 0;vertical-align:top}
  .vf-table td:first-child{color:var(--muted);padding-right:12px}
  .vf-table td:last-child{text-align:right;overflow-wrap:anywhere}
  .vf-hash{font-size:.7rem;word-break:break-all}
  @media (max-width:560px){
    .vf-card{padding:16px !important}
    .vf-table tr{display:block;border-bottom:1px solid rgba(0,0,0,.06);padding:6px 0}
    .vf-table td{display:block;padding:2px 0;text-align:left !important}
    .vf-table td:first-child{font-size:.8rem}
  }
</style>
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
    <div class="card vf-card">
      <p>Scan the QR code printed on an OpesInsure certificate to check it.<br>
      <span style="color:var(--muted)">Scannez le QR code imprimé sur une attestation OpesInsure pour la vérifier.</span></p>
    </div>
  @else
    <div class="card vf-card" role="status" aria-live="polite" data-result="{{ $r }}" data-status="{{ $state }}" style="border-left:6px solid {{ $color }}">
      <div class="vf-banner">
        <div style="width:2.25rem;height:2.25rem;flex:none;color:{{ $color }}">{{ svg('lucide-'.$icon, '', ['aria-hidden' => 'true', 'focusable' => 'false', 'style' => 'width:100%;height:100%']) }}</div>
        <div style="min-width:0">
          <span class="vf-state" style="background:{{ $color }}" data-testid="verify-state">{{ $stEn }} · {{ $stFr }}</span>
          <div style="font-size:1.35rem;font-weight:800;color:{{ $color }};margin-top:6px">{{ $en }}</div>
          <div style="color:var(--muted)">{{ $fr }}</div>
        </div>
      </div>
      <p style="margin-top:12px">{{ $stMsgEn }}<br><span style="color:var(--muted)">{{ $stMsgFr }}</span></p>
      @if($r !== 'not_found')
      <table class="vf-table">
        @if(!empty($doc))
        <tr><td>Document</td><td style="font-weight:700">{{ $doc['title_fr'] }} / {{ $doc['title_en'] }}</td></tr>
        <tr><td>Number / Numéro</td><td>{{ $doc['document_number'] ?? '—' }}</td></tr>
        <tr><td>Status / Statut</td><td>{{ $doc['status'] }}</td></tr>
        <tr><td>Issuer / Émetteur</td><td>{{ $doc['issuer_name'] ?? '—' }}</td></tr>
        <tr><td>Issued / Émis</td><td>{{ $fmt($doc['issued_at']) }}</td></tr>
        @if(!empty($doc['holder']))<tr><td>Holder / Titulaire</td><td>{{ $doc['holder'] }}</td></tr>@endif
        <tr><td>Policy / Police</td><td>{{ $doc['policy_reference'] ?? '—' }}</td></tr>
        @if(!empty($doc['vehicle']))<tr><td>Vehicle / Véhicule</td><td>{{ $doc['vehicle'] }}</td></tr>@endif
        @if(!empty($doc['revoked_at']))<tr><td>Revoked on / Révoqué le</td><td>{{ $fmt($doc['revoked_at']) }}</td></tr>@endif
        @if(!empty($doc['replaced_by']))<tr><td>Replaced by / Remplacé par</td><td>{{ $doc['replaced_by'] }}</td></tr>@endif
        <tr><td>SHA-256</td><td class="vf-hash">{{ $doc['sha256'] }}</td></tr>
        @else
        <tr><td>Certificate / Attestation</td><td style="font-weight:700">{{ $result['reference'] }}</td></tr>
        @endif
        <tr><td>Insurer / Assureur</td><td>{{ $result['carrier_name'] ?? '—' }}</td></tr>
        <tr><td>Class / Branche</td><td>{{ $result['product_class'] ?? '—' }}</td></tr>
        <tr><td>From / Du</td><td>{{ $fmt($result['coverage_starts_at'] ?? null) }}</td></tr>
        <tr><td>To / Au</td><td>{{ $fmt($result['coverage_ends_at'] ?? null) }}</td></tr>
      </table>
      <p style="margin-top:12px;font-size:.82rem;color:var(--muted)">Personal data is masked. The registry status above is authoritative; a QR code alone proves nothing.<br>Les données personnelles sont masquées. Seul le statut du registre ci-dessus fait foi&nbsp;; un QR code seul ne prouve rien.</p>
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
