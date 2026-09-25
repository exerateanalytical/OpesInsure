<!doctype html>
<html><head><meta charset="utf-8"><title>Certificate {{ $certificate->serial_number }}</title>@include('pdf._style')</head>
<body>@include('pdf._demo_overlay')
<table style="width:100%"><tr>
  <td>@include('pdf._letterhead', ['lhFallbackName' => 'OpesInsure'])<div class="muted">Certificate of insurance / Attestation d'assurance</div></td>
  <td style="text-align:right"><span class="status">{{ $policy->status }}</span></td>
</tr></table>
<div class="band" style="margin-top:12px">
  <strong>{{ $productName }}</strong> &nbsp;·&nbsp; {{ $carrierName }}
</div>
<table style="width:100%;margin-top:6px"><tr>
<td style="width:70%;vertical-align:top">
<table class="kv">
  <tr><td class="k">Certificate serial</td><td><strong>{{ $certificate->serial_number }}</strong></td></tr>
  <tr><td class="k">Policy number</td><td>{{ $policy->policy_number }}</td></tr>
  <tr><td class="k">Insured</td><td>{{ $insuredName }}</td></tr>
  <tr><td class="k">Insurer</td><td>{{ $carrierName }}</td></tr>
  <tr><td class="k">Class of insurance</td><td>{{ $lineCode }}</td></tr>
  @if(!empty($riskFacts['registration_number']))<tr><td class="k">Vehicle registration</td><td>{{ $riskFacts['registration_number'] }}</td></tr>@endif
  <tr><td class="k">Valid from</td><td>{{ optional($policy->coverage_starts_at)->format('d M Y H:i') }}</td></tr>
  <tr><td class="k">Valid until</td><td>{{ optional($policy->coverage_ends_at)->format('d M Y H:i') }}</td></tr>
  <tr><td class="k">Issued</td><td>{{ optional($certificate->issued_at)->format('d M Y H:i') }}</td></tr>
</table>
</td>
<td style="width:30%;text-align:center;vertical-align:top;padding-top:10px">
  <img class="qr" src="{{ $qr }}" alt="QR"><br>
  <span class="small muted">Scan to verify</span>
</td>
</tr></table>
<h2>Verification</h2>
<p>Verify this certificate at <strong>{{ $verifyUrl }}</strong>@if($verificationToken) — verification code: <span class="small">{{ $verificationToken }}</span>@endif.</p>
<p class="small muted">This digital attestation is issued on behalf of {{ $carrierName }}. Coverage is subject to the policy terms, conditions and exclusions set out in the policy schedule.</p>
@include('pdf._letterhead_footer')
</body></html>
