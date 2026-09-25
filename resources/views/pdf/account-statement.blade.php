@php($m = fn ($v) => number_format(((int) $v) / 100, 0, '.', ' '))
<!doctype html>
<html><head><meta charset="utf-8"><title>Statement {{ $s['statement_number'] }}</title>@include('pdf._style')</head>
<body>@include('pdf._demo_overlay')
<div class="brand">OpesInsure</div>
<div class="muted">Account statement / Relevé de compte</div>
<div class="band" style="margin-top:10px"><strong>{{ $s['statement_number'] }}</strong> &nbsp;·&nbsp; {{ $s['subject']['type'] }} &nbsp;·&nbsp; {{ $s['subject']['name'] ?? '—' }}</div>
<table class="kv">
  <tr><td class="k">Period</td><td>{{ $s['period_start'] }} → {{ $s['period_end'] }}</td></tr>
  <tr><td class="k">Currency</td><td>{{ $s['currency'] }}</td></tr>
  <tr><td class="k">Opening balance</td><td><strong>{{ $m($s['opening_balance_minor']) }}</strong></td></tr>
</table>
<table class="kv" style="margin-top:10px">
  <tr><td class="k">Date</td><td class="k">Type</td><td class="k">Description</td><td class="k">Amount</td><td class="k">Balance</td></tr>
  @forelse($s['lines'] as $l)
  <tr><td>{{ \Carbon\Carbon::parse($l['occurred_at'])->format('d M Y') }}</td><td>{{ $l['line_type'] }}</td><td>{{ $l['description'] }}</td><td>{{ $m($l['amount_minor']) }}</td><td>{{ $m($l['balance_minor']) }}</td></tr>
  @empty
  <tr><td colspan="5" class="muted">No transactions in this period.</td></tr>
  @endforelse
</table>
<table class="kv" style="margin-top:10px">
  <tr><td class="k">Closing balance</td><td><strong>{{ $m($s['closing_balance_minor']) }} {{ $s['currency'] }}</strong> ({{ $s['balance_meaning'] === 'OWED_BY_SUBJECT' ? 'amount due by you' : 'amount due to you' }})</td></tr>
</table>
<p class="small muted" style="margin-top:16px">Generated {{ now()->format('d M Y H:i') }} · {{ substr($s['content_hash'], 0, 16) }}</p>
</body></html>
