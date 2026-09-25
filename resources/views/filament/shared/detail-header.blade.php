{{-- REQ-UI-002 / SSR §23 detail header: canonical identifier, status, key metadata. --}}
@if ($summary)
    <div class="oi-detail-header" data-entity="{{ $summary->entity }}" style="display:flex;flex-wrap:wrap;gap:1rem 2rem;align-items:flex-start;justify-content:space-between;padding:1rem 1.25rem;border:1px solid var(--gray-200, #DCE3E8);border-radius:.75rem;background:var(--gray-50, #F5F7F8)">
        <div style="min-width:12rem">
            <div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--gray-600, #566776)">{{ $summary->entity }}</div>
            <div style="font-size:1.25rem;font-weight:700;font-variant-numeric:tabular-nums" data-testid="record-identifier">{{ $summary->identifier }}</div>
            <div style="color:var(--gray-700, #3C4C5B)">{{ $summary->title }}</div>
        </div>
        <div>
            <x-filament::badge :color="$summary->tone" data-testid="record-status">{{ $summary->status }}</x-filament::badge>
        </div>
        @if ($summary->metadata)
            <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.5rem 1.5rem;flex:1 1 24rem;margin:0">
                @foreach ($summary->metadata as $label => $value)
                    <div>
                        <dt style="font-size:.75rem;color:var(--gray-600, #566776)">{{ $label }}</dt>
                        <dd style="margin:0;font-weight:600">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
@endif
