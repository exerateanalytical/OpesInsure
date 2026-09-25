{{-- Canonical UI handoff: status = outline icon + readable label + colour (never colour alone).
     The stable status code stays in data-status (API value); the specific state is never collapsed to a generic one.
     @include('filament.shared.status-badge', ['status' => 'PAID_PENDING_ISSUANCE', 'tone' => null, 'label' => null]) --}}
@php
    $code = (string) ($status ?? '');
    $tone = $tone ?? \App\Application\WebExperiences\RecordSummary::toneFor($code);
    $text = $label ?? \App\Application\WebExperiences\RecordSummary::labelFor($code);
@endphp
@if ($code !== '')
    <span class="oi-status oi-status--{{ $tone }}" data-status="{{ $code }}" data-tone="{{ $tone }}">{{ svg(\App\Application\WebExperiences\RecordSummary::iconFor($tone), '', ['aria-hidden' => 'true', 'focusable' => 'false']) }}<span>{{ $text }}</span></span>
@endif
