{{-- SSR §29 authority widget. Rule internals appear only when the assessor returned them (approvals.matrix.view). --}}
@if ($assessment)
    @php($a = $assessment)
    <dl data-testid="authority-widget" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem 1.5rem;margin:0">
        <div><dt style="font-size:.75rem;color:var(--gray-600, #566776)">{{ __('web_experience.authority.amount') }}</dt>
            <dd style="margin:0;font-weight:600">{{ \App\Application\WebExperiences\Money::format($a['amount_minor'], $a['currency']) }}</dd></div>
        <div><dt style="font-size:.75rem;color:var(--gray-600, #566776)">{{ __('web_experience.authority.within') }}</dt>
            <dd style="margin:0"><x-filament::badge :color="$a['within_authority'] ? 'success' : 'danger'" data-testid="authority-within">{{ $a['within_authority'] ? __('web_experience.authority.yes') : __('web_experience.authority.no') }}</x-filament::badge></dd></div>
        <div><dt style="font-size:.75rem;color:var(--gray-600, #566776)">{{ __('web_experience.authority.referral') }}</dt>
            <dd style="margin:0"><x-filament::badge :color="$a['referral_required'] ? 'warning' : 'success'">{{ $a['referral_required'] ? __('web_experience.authority.yes') : __('web_experience.authority.no') }}</x-filament::badge></dd></div>
        @if ($a['may_view_rules'])
            <div><dt style="font-size:.75rem;color:var(--gray-600, #566776)">{{ __('web_experience.authority.yours') }}</dt><dd style="margin:0">{{ $a['your_authority'] ?: '—' }}</dd></div>
            <div data-testid="authority-required"><dt style="font-size:.75rem;color:var(--gray-600, #566776)">{{ __('web_experience.authority.required') }}</dt>
                <dd style="margin:0">
                    @if ($a['required_authority'])
                        @foreach ($a['required_authority'] as $k => $v)<div>{{ $k }}: {{ is_array($v) ? implode(', ', $v) : $v }}</div>@endforeach
                    @else — @endif
                </dd></div>
        @else
            <p style="grid-column:1/-1;margin:0;font-size:.8125rem;color:var(--gray-600, #566776)">{{ __('web_experience.authority.restricted') }}</p>
        @endif
        @unless ($a['rule_found'])
            <p style="grid-column:1/-1;margin:0;font-size:.8125rem;color:#764B00">{{ __('web_experience.authority.no_rule') }}</p>
        @endunless
    </dl>
@endif
