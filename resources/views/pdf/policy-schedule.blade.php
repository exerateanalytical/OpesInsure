<!doctype html>
<html><head><meta charset="utf-8"><title>Policy schedule {{ $policy->policy_number }}</title>@include('pdf._style')</head>
<body>@include('pdf._demo_overlay')
<table style="width:100%"><tr>
  <td>@include('pdf._letterhead', ['lhFallbackName' => 'OpesInsure'])<div class="muted">Policy schedule / Conditions particulières</div></td>
  <td style="text-align:right"><img src="{{ $qr }}" style="width:80px;height:80px" alt="QR"></td>
</tr></table>
<div class="band" style="margin-top:8px"><strong>{{ $productName }}</strong> &nbsp;·&nbsp; {{ $carrierName }}</div>
<h2>Policy</h2>
<table class="kv">
  <tr><td class="k">Policy number</td><td><strong>{{ $policy->policy_number }}</strong></td></tr>
  <tr><td class="k">Certificate serial</td><td>{{ $certificate->serial_number }}</td></tr>
  <tr><td class="k">Policyholder</td><td>{{ $insuredName }}</td></tr>
  <tr><td class="k">Insurer</td><td>{{ $carrierName }}</td></tr>
  <tr><td class="k">Period of cover</td><td>{{ optional($policy->coverage_starts_at)->format('d M Y') }} — {{ optional($policy->coverage_ends_at)->format('d M Y') }}</td></tr>
  <tr><td class="k">Issued</td><td>{{ optional($policy->issued_at)->format('d M Y H:i') }}</td></tr>
</table>
<h2>Premium</h2>
<table class="kv">
  <tr><td class="k">Premium</td><td>{{ number_format(((int)($terms['premium_minor'] ?? 0))/100, 0, '.', ' ') }} {{ $terms['currency'] ?? $policy->currency }}</td></tr>
  <tr><td class="k">Taxes</td><td>{{ number_format(((int)($terms['tax_minor'] ?? 0))/100, 0, '.', ' ') }} {{ $terms['currency'] ?? $policy->currency }}</td></tr>
  <tr><td class="k">Fees</td><td>{{ number_format(((int)($terms['fee_minor'] ?? 0))/100, 0, '.', ' ') }} {{ $terms['currency'] ?? $policy->currency }}</td></tr>
  <tr><td class="k"><strong>Total paid</strong></td><td><strong>{{ number_format(((int)($terms['total_minor'] ?? $policy->premium_minor))/100, 0, '.', ' ') }} {{ $terms['currency'] ?? $policy->currency }}</strong></td></tr>
</table>
@if(!empty($riskFacts))
<h2>Insured risk</h2>
<table class="kv">
@foreach($riskFacts as $key => $value)
  @if(is_scalar($value))<tr><td class="k">{{ ucwords(str_replace('_', ' ', (string) $key)) }}</td><td>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</td></tr>@endif
@endforeach
</table>
@endif
@if(!empty($coverages))
<h2>Coverages</h2>
<table class="kv">
@foreach($coverages as $c)
  <tr><td class="k">{{ is_array($c) ? ($c['name']['en'] ?? $c['name'] ?? $c['code'] ?? '') : $c }}</td><td>@if(is_array($c) && isset($c['limit_minor'])){{ number_format(((int)$c['limit_minor'])/100, 0, '.', ' ') }}@endif</td></tr>
@endforeach
</table>
@endif
<p class="small muted" style="margin-top:18px">Verify at {{ $verifyUrl }}. This schedule forms part of your policy with {{ $carrierName }}.</p>
@include('pdf._letterhead_footer')
</body></html>
