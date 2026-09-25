{{-- REQ-UI-002 / SSR §23 detail header: canonical identifier, status (icon + label), key metadata. --}}
@if ($summary)
    <section class="oi-card oi-detail-header" data-entity="{{ $summary->entity }}" aria-label="{{ $summary->entity }} {{ $summary->identifier }}">
        <div style="min-width:12rem">
            <p class="oi-detail-header__entity">{{ $summary->entity }}</p>
            <p class="oi-detail-header__id oi-num" data-testid="record-identifier">{{ $summary->identifier }}</p>
            <p class="oi-muted" style="margin:0">{{ $summary->title }}</p>
        </div>
        <div data-testid="record-status">
            <span class="sr-only">{{ __('web_experience.status.label') }}:</span>
            @include('filament.shared.status-badge', ['status' => $summary->status, 'tone' => $summary->tone])
        </div>
        @if ($summary->metadata)
            <dl class="oi-dl">
                @foreach ($summary->metadata as $label => $value)
                    <div>
                        <dt class="oi-label">{{ $label }}</dt>
                        <dd class="oi-value oi-num">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
@endif
