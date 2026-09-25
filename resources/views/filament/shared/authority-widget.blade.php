{{-- SSR §29 authority widget. Rule internals appear only when the assessor returned them (approvals.matrix.view). --}}
@if ($assessment)
    @php($a = $assessment)
    <dl data-testid="authority-widget" class="oi-dl">
        <div><dt class="oi-label">{{ __('web_experience.authority.amount') }}</dt>
            <dd class="oi-value oi-money">{{ \App\Application\WebExperiences\Money::display($a['amount_minor'], $a['currency']) }}</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.authority.within') }}</dt>
            <dd class="oi-value" data-testid="authority-within">@include('filament.shared.status-badge', ['status' => $a['within_authority'] ? 'YES' : 'NO', 'tone' => $a['within_authority'] ? 'success' : 'danger', 'label' => $a['within_authority'] ? __('web_experience.authority.yes') : __('web_experience.authority.no')])</dd></div>
        <div><dt class="oi-label">{{ __('web_experience.authority.referral') }}</dt>
            <dd class="oi-value">@include('filament.shared.status-badge', ['status' => $a['referral_required'] ? 'YES' : 'NO', 'tone' => $a['referral_required'] ? 'warning' : 'success', 'label' => $a['referral_required'] ? __('web_experience.authority.yes') : __('web_experience.authority.no')])</dd></div>
        @if ($a['may_view_rules'])
            <div><dt class="oi-label">{{ __('web_experience.authority.yours') }}</dt><dd class="oi-value">{{ $a['your_authority'] ?: '—' }}</dd></div>
            <div data-testid="authority-required"><dt class="oi-label">{{ __('web_experience.authority.required') }}</dt>
                <dd class="oi-value">
                    @if ($a['required_authority'])
                        @foreach ($a['required_authority'] as $k => $v)<div>{{ $k }}: {{ is_array($v) ? implode(', ', $v) : $v }}</div>@endforeach
                    @else — @endif
                </dd></div>
        @else
            <p class="oi-muted" style="grid-column:1/-1;margin:0;font-size:.8125rem">{{ __('web_experience.authority.restricted') }}</p>
        @endif
        @unless ($a['rule_found'])
            <p class="oi-disabled-reason" style="grid-column:1/-1;margin:0;font-size:.8125rem">{{ __('web_experience.authority.no_rule') }}</p>
        @endunless
    </dl>
@endif
