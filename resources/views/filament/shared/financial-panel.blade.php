{{-- SSR §28 financial panel: amount, status, source, payer, payee, reference, reconciliation, journal. Labelled cards under 768 px. --}}
<div class="oi-financial" data-testid="financial-panel">
    @if ($rows === [])
        <p class="oi-empty">{{ __('web_experience.financial.empty') }}</p>
    @else
        <table class="oi-records">
            <caption class="sr-only">{{ __('web_experience.financial.heading') }}</caption>
            <thead><tr>
                <th scope="col"><span class="sr-only">{{ __('web_experience.financial.heading') }}</span></th><th scope="col">{{ __('web_experience.financial.amount') }}</th><th scope="col">{{ __('web_experience.financial.status') }}</th><th scope="col">{{ __('web_experience.financial.source') }}</th>
                <th scope="col">{{ __('web_experience.financial.payer') }}</th><th scope="col">{{ __('web_experience.financial.payee') }}</th><th scope="col">{{ __('web_experience.financial.reference') }}</th>
                <th scope="col">{{ __('web_experience.financial.reconciliation') }}</th><th scope="col">{{ __('web_experience.financial.journal') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $f)
                <tr>
                    <td data-label="" style="font-weight:600">{{ $f['label'] }}</td>
                    <td data-label="{{ __('web_experience.financial.amount') }}" class="oi-money">{{ $f['amount'] }}</td>
                    <td data-label="{{ __('web_experience.financial.status') }}">@if ($f['status'])@include('filament.shared.status-badge', ['status' => (string) $f['status']])@else — @endif</td>
                    <td data-label="{{ __('web_experience.financial.source') }}">{{ $f['source'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.financial.payer') }}">{{ $f['payer'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.financial.payee') }}">{{ $f['payee'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.financial.reference') }}" class="oi-num">{{ $f['reference'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.financial.reconciliation') }}">{{ $f['reconciliation'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.financial.journal') }}" class="oi-num">{{ $f['journal'] ? \Illuminate\Support\Str::limit($f['journal'], 8, '…') : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
