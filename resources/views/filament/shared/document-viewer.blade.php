{{-- SSR §27 document viewer (presentation only): number, version, status, verification, issuer, issue/expiry, replacement, QR verification.
     Rows are already filtered by DocumentAccessPolicy (document security is owned by the documents workstream, cs1); this view only renders what it is given.
     Under 768 px each row becomes a labelled card (no squeezed desktop table). --}}
<div class="oi-documents" data-testid="document-viewer">
    @if ($withheld > 0)
        <div class="oi-state oi-state--warning" role="note" style="margin:0 0 .75rem">
            {{ svg('lucide-lock', '', ['aria-hidden' => 'true', 'focusable' => 'false']) }}
            <p class="oi-state__body">{{ trans('web_experience.documents.withheld', ['count' => $withheld]) }}</p>
        </div>
    @endif
    @if ($rows === [])
        <p class="oi-empty">{{ __('web_experience.documents.empty') }}</p>
    @else
        <table class="oi-records">
            <caption class="sr-only">{{ __('web_experience.documents.heading') }}</caption>
            <thead><tr>
                <th scope="col">{{ __('web_experience.documents.heading') }}</th><th scope="col">{{ __('web_experience.documents.number') }}</th><th scope="col">{{ __('web_experience.documents.version') }}</th>
                <th scope="col">{{ __('web_experience.documents.status') }}</th><th scope="col">{{ __('web_experience.documents.verification') }}</th><th scope="col">{{ __('web_experience.documents.issuer') }}</th>
                <th scope="col">{{ __('web_experience.documents.issued') }}</th><th scope="col">{{ __('web_experience.documents.expires') }}</th><th scope="col">{{ __('web_experience.documents.replaces') }}</th>
                <th scope="col"><span class="sr-only">{{ __('web_experience.documents.verify') }}</span></th>
            </tr></thead>
            <tbody>
            @foreach ($rows as $d)
                <tr>
                    <td data-label="{{ __('web_experience.documents.heading') }}" style="font-weight:600">{{ $d['title'] }}</td>
                    <td data-label="{{ __('web_experience.documents.number') }}" class="oi-num">{{ $d['number'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.documents.version') }}" class="oi-num">{{ $d['version'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.documents.status') }}">@if ($d['status'])@include('filament.shared.status-badge', ['status' => (string) $d['status']])@else — @endif</td>
                    <td data-label="{{ __('web_experience.documents.verification') }}">{{ $d['verification'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.documents.issuer') }}">{{ $d['issuer'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.documents.issued') }}" class="oi-num">{{ $d['issued_at'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.documents.expires') }}" class="oi-num">{{ $d['expires_at'] ?? '—' }}</td>
                    <td data-label="{{ __('web_experience.documents.replaces') }}" class="oi-num">{{ $d['replaces'] ? \Illuminate\Support\Str::limit($d['replaces'], 8, '…') : '—' }}</td>
                    <td data-label="">@if ($d['verify_url'])<a href="{{ $d['verify_url'] }}" target="_blank" rel="noopener" class="fi-link" style="color:var(--oi-blue-600);font-weight:600">{{ __('web_experience.documents.verify') }}</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
