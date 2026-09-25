{{-- Canonical handoff: approval interfaces show maker, checker, requested change, evidence and before/after values;
     self-approval is DISABLED WITH AN EXPLANATION (not hidden). Presentation only: ApprovalService still enforces maker-checker/SoD.
     $approval: ApprovalPanelData::for() output. --}}
@php($a = $approval)
<section class="oi-stack" data-testid="approval-panel">
    <div style="display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:space-between">
        <p class="oi-value" style="font-size:1rem">{{ $a['change'] }}</p>
        @include('filament.shared.status-badge', ['status' => $a['status']])
    </div>
    <dl class="oi-dl">
        <div><dt class="oi-label">{{ __('web_experience.approval.maker') }}</dt><dd class="oi-value" data-testid="approval-maker">{{ $a['maker'] ?? '—' }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.approval.requested_at') }}</dt><dd class="oi-value oi-num">{{ $a['requested_at'] ?? '—' }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.approval.checker') }}</dt><dd class="oi-value" data-testid="approval-checker">{{ $a['checker'] ?? __('web_experience.approval.awaiting_checker') }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.approval.decided_at') }}</dt><dd class="oi-value oi-num">{{ $a['decided_at'] ?? '—' }}</dd></div>
        @if (! empty($a['amount']))
            <div><dt class="oi-label">{{ __('web_experience.authority.amount') }}</dt><dd class="oi-value oi-money">{{ $a['amount'] }}</dd></div>
        @endif
    </dl>
    <div>
        <p class="oi-label">{{ __('web_experience.approval.reason') }}</p>
        <p style="margin:0">{{ $a['reason'] ?: '—' }}</p>
    </div>
    <div>
        <p class="oi-label">{{ __('web_experience.approval.evidence') }}</p>
        @if ($a['evidence'] === [])
            <p class="oi-muted" style="margin:0">{{ __('web_experience.approval.no_evidence') }}</p>
        @else
            <ul style="margin:0;padding-left:1.25rem">@foreach ($a['evidence'] as $ev)<li class="oi-num">{{ $ev }}</li>@endforeach</ul>
        @endif
    </div>
    <div style="overflow-x:auto">
        @if ($a['diff'] === [])
            <p class="oi-muted" style="margin:0">{{ __('web_experience.approval.no_diff') }}</p>
        @else
            <table class="oi-diff">
                <caption class="sr-only">{{ __('web_experience.approval.change') }}</caption>
                <thead><tr><th scope="col">{{ __('web_experience.approval.field') }}</th><th scope="col">{{ __('web_experience.approval.before') }}</th><th scope="col">{{ __('web_experience.approval.after') }}</th></tr></thead>
                <tbody>
                @foreach ($a['diff'] as $row)
                    <tr><th scope="row" style="font-weight:600">{{ $row['field'] }}</th><td class="oi-diff__before">{{ $row['before'] ?? '—' }}</td><td class="oi-diff__after">{{ $row['after'] ?? '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    @if ($a['self_approval'])
        <p class="oi-disabled-reason" role="note" data-testid="self-approval-reason">
            {{ svg('lucide-ban', '', ['aria-hidden' => 'true', 'focusable' => 'false', 'style' => 'width:18px;height:18px;flex:none']) }}
            <span>{{ __('web_experience.approval.self_approval_disabled') }}</span>
        </p>
    @endif
    @foreach ($a['blockers'] as $blocker)
        <p class="oi-disabled-reason" role="note">{{ $blocker }}</p>
    @endforeach
</section>
