<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $documentNumber }}</title>@include('pdf._style')</head>
<body>@include('pdf._demo_overlay')
{{-- Document engine generic layout: every issued document carries issuer, number, verification code and (when configured) a QR. --}}
<table style="width:100%"><tr>
  <td>
    <div class="brand">{{ $issuerName }}</div>
    @if($lang !== 'EN')<div class="muted">{{ $titleFr }}</div>@endif
    @if($lang !== 'FR')<div class="muted">{{ $titleEn }}</div>@endif
  </td>
  <td style="text-align:right"><span class="status">{{ $documentNumber }}</span></td>
</tr></table>
@if($intermediary)
<div class="band" style="margin-top:8px">
  {{ $lang === 'EN' ? 'Insurance intermediary' : ($lang === 'FR' ? "Intermédiaire d'assurance" : "Intermédiaire d'assurance / Insurance intermediary") }}:
  <strong>{{ $intermediary['name'] }}</strong>@if($intermediary['licence']) ({{ $intermediary['licence'] }})@endif
  — {{ $lang === 'EN' ? 'on behalf of insurer' : ($lang === 'FR' ? "pour le compte de l'assureur" : "pour le compte de l'assureur / on behalf of insurer") }} <strong>{{ $carrierName }}</strong>
</div>
@endif
<table style="width:100%;margin-top:8px"><tr>
<td style="width:72%;vertical-align:top">
<table class="kv">
  <tr><td class="k">{{ $lang === 'EN' ? 'Insurer' : ($lang === 'FR' ? 'Assureur' : 'Assureur / Insurer') }}</td><td>{{ $carrierName }}</td></tr>
  @if($policyNumber)<tr><td class="k">{{ $lang === 'EN' ? 'Policy number' : ($lang === 'FR' ? 'N° de police' : 'N° de police / Policy number') }}</td><td><strong>{{ $policyNumber }}</strong> (v{{ $policyVersion }})</td></tr>@endif
  <tr><td class="k">{{ $lang === 'EN' ? 'Insured' : ($lang === 'FR' ? 'Assuré' : 'Assuré / Insured') }}</td><td>{{ $insuredName }}</td></tr>
  @if($productName)<tr><td class="k">{{ $lang === 'EN' ? 'Product' : ($lang === 'FR' ? 'Produit' : 'Produit / Product') }}</td><td>{{ $productName }}</td></tr>@endif
  @if($subjectLabel)<tr><td class="k">{{ $lang === 'EN' ? 'Insured item' : ($lang === 'FR' ? 'Objet assuré' : 'Objet assuré / Insured item') }}</td><td><strong>{{ $subjectLabel }}</strong></td></tr>@endif
  @if($validFrom)<tr><td class="k">{{ $lang === 'EN' ? 'Valid from' : ($lang === 'FR' ? 'Valable du' : 'Du / From') }}</td><td>{{ $validFrom }}</td></tr>@endif
  @if($validUntil)<tr><td class="k">{{ $lang === 'EN' ? 'Valid until' : ($lang === 'FR' ? "Valable jusqu'au" : 'Au / Until') }}</td><td>{{ $validUntil }}</td></tr>@endif
  @if($eventLabel)<tr><td class="k">{{ $lang === 'EN' ? 'Reference' : ($lang === 'FR' ? 'Référence' : 'Référence / Reference') }}</td><td>{{ $eventLabel }}</td></tr>@endif
  <tr><td class="k">{{ $lang === 'EN' ? 'Issued' : ($lang === 'FR' ? 'Émis le' : 'Émis le / Issued') }}</td><td>{{ $issuedAt }}</td></tr>
  <tr><td class="k">{{ $lang === 'EN' ? 'Verification code' : ($lang === 'FR' ? 'Code de vérification' : 'Code de vérification / Verification code') }}</td><td><strong>{{ $verificationCode }}</strong></td></tr>
</table>
</td>
<td style="width:28%;text-align:center;vertical-align:top;padding-top:6px">
  @if($qr)<img class="qr" src="{{ $qr }}" alt="QR"><br><span class="small muted">{{ $lang === 'EN' ? 'Scan to verify' : ($lang === 'FR' ? 'Scanner pour vérifier' : 'Scanner pour vérifier / Scan to verify') }}</span>@endif
</td>
</tr></table>
@foreach($sections as $section)
  @if(!empty($section['heading']))<h2>{{ $section['heading'] }}</h2>@endif
  @foreach($section['paragraphs'] as $p)<p>{{ $p }}</p>@endforeach
@endforeach
@if(!empty($coverages))
<h2>{{ $lang === 'EN' ? 'Cover' : ($lang === 'FR' ? 'Garanties' : 'Garanties / Cover') }}</h2>
<table class="kv">@foreach($coverages as $c)<tr><td class="k">{{ is_array($c) ? ($c['name'] ?? $c['code'] ?? '') : $c }}</td><td>{{ is_array($c) ? ($c['limit_minor'] ?? $c['sum_insured_minor'] ?? '') : '' }}</td></tr>@endforeach</table>
@endif
@if($signatory)
<p style="margin-top:18px">{{ $signatory['name'] }}<br><span class="small muted">{{ $signatory['title'] }}</span></p>
@endif
<p class="small muted" style="margin-top:14px">{{ $verifyUrl }} · {{ $templateRef }}</p>
</body></html>
