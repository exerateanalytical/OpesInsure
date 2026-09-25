{{-- Canonical handoff: persistent payment state panel (no endless spinner). Shows network, customer phone, each amount line and
     total, the explicit state with its recovery guidance, the timeout, and retry / receipt-recovery links when the caller provides them.
     $payment: ['status' (stable code, shown as-is),'state' (copy key: PENDING|PROCESSING|SUCCEEDED|FAILED|EXPIRED|CANCELLED|UNKNOWN),'network','phone','reference','started_at','timeout_at','currency','lines'=>[key=>?minor],'total'=>?minor,'retry_url','receipt_url'] --}}
@php
    $p = $payment;
    $status = strtoupper((string) ($p['status'] ?? 'UNKNOWN'));
    $known = ['PENDING', 'PROCESSING', 'SUCCEEDED', 'FAILED', 'EXPIRED', 'CANCELLED'];
    $copy = strtoupper((string) ($p['state'] ?? (in_array($status, $known, true) ? $status : 'UNKNOWN')));
    $copy = in_array($copy, [...$known, 'UNKNOWN'], true) ? $copy : 'UNKNOWN';
    $tone = match ($copy) { 'SUCCEEDED' => 'success', 'FAILED' => 'danger', 'EXPIRED', 'CANCELLED' => 'gray', 'UNKNOWN' => 'warning', default => 'info' };
@endphp
<section class="oi-card oi-stack" data-testid="payment-state" data-status="{{ $status }}" aria-labelledby="oi-pay-h">
    <div style="display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between">
        <h3 id="oi-pay-h" style="margin:0;font-size:1rem">{{ __('web_experience.payment.heading') }}</h3>
        @include('filament.shared.status-badge', ['status' => $status, 'tone' => $tone])
    </div>
    <div class="oi-state oi-state--{{ $tone }}" role="status" aria-live="polite" style="margin:0">
        {{ svg(\App\Application\WebExperiences\RecordSummary::iconFor($tone), '', ['aria-hidden' => 'true', 'focusable' => 'false']) }}
        <p class="oi-state__body">{{ __('web_experience.payment.states.'.$copy) }}</p>
    </div>
    <dl class="oi-dl">
        <div><dt class="oi-label">{{ __('web_experience.payment.network') }}</dt><dd class="oi-value">{{ $p['network'] ?? '—' }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.payment.phone') }}</dt><dd class="oi-value oi-num">{{ $p['phone'] ?? '—' }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.payment.reference') }}</dt><dd class="oi-value oi-num">{{ $p['reference'] ?? '—' }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.payment.started') }}</dt><dd class="oi-value oi-num">{{ $p['started_at'] ?? '—' }}</dd></div>
        @if (! empty($p['timeout_at']) && in_array($copy, ['PENDING', 'PROCESSING'], true))
            <div><dt class="oi-label">{{ __('web_experience.payment.timeout_at') }}</dt><dd class="oi-value oi-num">{{ $p['timeout_at'] }}</dd></div>
        @endif
    </dl>
    @if (! empty($p['lines']))
        @include('filament.shared.financial-breakdown', ['lines' => $p['lines'], 'total' => $p['total'] ?? null, 'currency' => $p['currency'] ?? 'XAF', 'id' => 'pay'])
    @endif
    @if (! empty($p['retry_url']) && in_array($copy, ['FAILED', 'EXPIRED'], true))
        <div class="oi-state__actions"><a class="fi-btn" href="{{ $p['retry_url'] }}" style="display:inline-flex;align-items:center;padding:0 1rem;background:var(--oi-blue-600);color:#fff">{{ __('web_experience.payment.retry') }}</a></div>
    @endif
    @if (! empty($p['receipt_url']) && in_array($copy, ['SUCCEEDED', 'UNKNOWN'], true))
        <div class="oi-state__actions"><a class="fi-link" href="{{ $p['receipt_url'] }}" style="color:var(--oi-blue-600);font-weight:600">{{ __('web_experience.payment.recover_receipt') }}</a></div>
    @endif
</section>
