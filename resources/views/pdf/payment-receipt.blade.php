<!doctype html>
<html><head><meta charset="utf-8"><title>Receipt {{ $r['receipt_number'] }}</title>@include('pdf._style')</head>
<body>@include('pdf._demo_overlay')
@include('pdf._letterhead', ['lhFallbackName' => 'OpesInsure'])
<div class="muted">Payment receipt / Reçu de paiement</div>
<div class="band" style="margin-top:10px"><strong>{{ $r['receipt_number'] }}</strong> &nbsp;·&nbsp; {{ $r['status'] }}</div>
<table class="kv">
  <tr><td class="k">Amount</td><td><strong>{{ number_format(((int) $r['amount_minor'])/100, 0, '.', ' ') }} {{ $r['currency'] }}</strong></td></tr>
  <tr><td class="k">Paid on</td><td>{{ $r['issued_at'] ? \Carbon\Carbon::parse($r['issued_at'])->format('d M Y H:i') : '—' }}</td></tr>
  <tr><td class="k">Payer</td><td>{{ $r['payer_name'] ?? '—' }} {{ $r['payer_phone_e164'] }}</td></tr>
  <tr><td class="k">Method</td><td>{{ strtoupper(str_replace('_', ' ', (string) $r['provider'])) }}</td></tr>
  <tr><td class="k">Provider reference</td><td>{{ $r['reference'] }}</td></tr>
  <tr><td class="k">Product</td><td>{{ $r['product_name'] ?? '—' }}</td></tr>
  <tr><td class="k">Insurer</td><td>{{ $r['carrier_name'] ?? '—' }}</td></tr>
  @if($r['policy_number'])<tr><td class="k">Policy</td><td>{{ $r['policy_number'] }}</td></tr>@endif
</table>
<p class="small muted" style="margin-top:16px">Receipt generated {{ now()->format('d M Y H:i') }}. Keep this receipt for your records.</p>
@include('pdf._letterhead_footer')
</body></html>
