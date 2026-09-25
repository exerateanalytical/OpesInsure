{{-- SSR §28 financial panel: amount, status, source, payer, payee, reference, reconciliation, journal. --}}
<div class="oi-financial" data-testid="financial-panel" style="overflow-x:auto">
    @if ($rows === [])
        <p style="color:var(--gray-600, #566776)">{{ __('web_experience.financial.empty') }}</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead><tr style="text-align:left;color:var(--gray-600, #566776)">
                <th style="padding:.375rem"></th><th>{{ __('web_experience.financial.amount') }}</th><th>{{ __('web_experience.financial.status') }}</th><th>{{ __('web_experience.financial.source') }}</th>
                <th>{{ __('web_experience.financial.payer') }}</th><th>{{ __('web_experience.financial.payee') }}</th><th>{{ __('web_experience.financial.reference') }}</th>
                <th>{{ __('web_experience.financial.reconciliation') }}</th><th>{{ __('web_experience.financial.journal') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $f)
                <tr style="border-top:1px solid var(--gray-200, #DCE3E8)">
                    <td style="padding:.375rem;font-weight:600">{{ $f['label'] }}</td>
                    <td style="font-variant-numeric:tabular-nums;white-space:nowrap">{{ $f['amount'] }}</td>
                    <td>@if ($f['status'])<x-filament::badge size="sm" :color="\App\Application\WebExperiences\RecordSummary::toneFor((string) $f['status'])">{{ $f['status'] }}</x-filament::badge>@endif</td>
                    <td>{{ $f['source'] ?? '—' }}</td><td>{{ $f['payer'] ?? '—' }}</td><td>{{ $f['payee'] ?? '—' }}</td><td>{{ $f['reference'] ?? '—' }}</td>
                    <td>{{ $f['reconciliation'] ?? '—' }}</td><td>{{ $f['journal'] ? \Illuminate\Support\Str::limit($f['journal'], 8, '…') : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
