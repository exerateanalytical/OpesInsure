{{-- SSR §27 document viewer: number, version, status, verification, issuer, issue/expiry, replacement, QR verification. Rows already filtered by DocumentAccessPolicy. --}}
<div class="oi-documents" data-testid="document-viewer" style="overflow-x:auto">
    @if ($withheld > 0)
        <p role="note" style="margin:0 0 .5rem;color:#764B00">{{ trans('web_experience.documents.withheld', ['count' => $withheld]) }}</p>
    @endif
    @if ($rows === [])
        <p style="color:var(--gray-600, #566776)">{{ __('web_experience.documents.empty') }}</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead><tr style="text-align:left;color:var(--gray-600, #566776)">
                <th style="padding:.375rem">{{ __('web_experience.documents.heading') }}</th><th>{{ __('web_experience.documents.number') }}</th><th>{{ __('web_experience.documents.version') }}</th>
                <th>{{ __('web_experience.documents.status') }}</th><th>{{ __('web_experience.documents.verification') }}</th><th>{{ __('web_experience.documents.issuer') }}</th>
                <th>{{ __('web_experience.documents.issued') }}</th><th>{{ __('web_experience.documents.expires') }}</th><th>{{ __('web_experience.documents.replaces') }}</th><th></th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $d)
                <tr style="border-top:1px solid var(--gray-200, #DCE3E8)">
                    <td style="padding:.375rem;font-weight:600">{{ $d['title'] }}</td><td>{{ $d['number'] ?? '—' }}</td><td>{{ $d['version'] ?? '—' }}</td>
                    <td>@if ($d['status'])<x-filament::badge size="sm" :color="\App\Application\WebExperiences\RecordSummary::toneFor((string) $d['status'])">{{ $d['status'] }}</x-filament::badge>@endif</td>
                    <td>{{ $d['verification'] ?? '—' }}</td><td>{{ $d['issuer'] ?? '—' }}</td><td>{{ $d['issued_at'] ?? '—' }}</td><td>{{ $d['expires_at'] ?? '—' }}</td>
                    <td>{{ $d['replaces'] ? \Illuminate\Support\Str::limit($d['replaces'], 8, '…') : '—' }}</td>
                    <td>@if ($d['verify_url'])<a href="{{ $d['verify_url'] }}" target="_blank" rel="noopener" style="color:#155FCC">{{ __('web_experience.documents.verify') }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
