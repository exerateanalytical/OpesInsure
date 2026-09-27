{{-- REQ-UI-002 related records tab (presentation only). Groups come from App\Application\WebExperiences\RelatedRecordsQuery; links only when the current panel exposes the related record. --}}
<div class="oi-related" data-testid="related-records">
    @if ($groups === [])
        <p class="oi-empty">{{ __('web_experience.related.empty') }}</p>
    @else
        @foreach ($groups as $g)
            <section style="margin:0 0 1.25rem" data-group="{{ $g['key'] }}">
                <h3 class="oi-label" style="margin:0 0 .5rem">{{ $g['heading'] }} <span class="oi-muted">({{ count($g['rows']) }})</span></h3>
                <table class="oi-records">
                    <caption class="sr-only">{{ $g['heading'] }}</caption>
                    <thead><tr>
                        <th scope="col">{{ __('web_experience.related.record') }}</th><th scope="col">{{ __('web_experience.related.detail') }}</th><th scope="col">{{ __('web_experience.documents.status') }}</th>
                        <th scope="col"><span class="sr-only">{{ __('web_experience.related.open') }}</span></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($g['rows'] as $r)
                        <tr>
                            <td data-label="{{ __('web_experience.related.record') }}" class="oi-num" style="font-weight:600">{{ $r['label'] }}</td>
                            <td data-label="{{ __('web_experience.related.detail') }}">{{ filled($r['detail']) ? $r['detail'] : '—' }}</td>
                            <td data-label="{{ __('web_experience.documents.status') }}">@if (filled($r['status']))@include('filament.shared.status-badge', ['status' => (string) $r['status']])@else — @endif</td>
                            <td data-label="">@if ($r['url'])<a href="{{ $r['url'] }}" class="fi-link" style="color:var(--oi-blue-600);font-weight:600">{{ __('web_experience.related.open') }}</a>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach
    @endif
</div>
