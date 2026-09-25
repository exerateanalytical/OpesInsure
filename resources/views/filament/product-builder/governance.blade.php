{{-- REQ-PRD-007 PRE §74 workflow stepper + history + governance attributes. --}}
<div style="display:grid;gap:.75rem">
    <ol style="display:flex;flex-wrap:wrap;gap:.35rem;list-style:none;padding:0;margin:0" aria-label="{{ __('product_builder.governance.workflow') }}">
        @foreach (array_diff($stages, ['REJECTED']) as $s)
            <li data-stage="{{ $s }}" @if ($s === $state->stage) aria-current="step" @endif style="padding:.25rem .6rem;border-radius:999px;font-size:.8rem;{{ $s === $state->stage ? 'background:#1F4E8C;color:#fff' : 'background:#EEF2F7;color:#334' }}">{{ __('product_builder.stages.'.$s) }}</li>
        @endforeach
        @if ($state->stage === 'REJECTED')
            <li data-stage="REJECTED" aria-current="step" style="padding:.25rem .6rem;border-radius:999px;background:#FDEDEF;color:#98272E">{{ __('product_builder.stages.REJECTED') }}</li>
        @endif
    </ol>
    <div>{{ __('product_builder.governance.next') }}: <strong>{{ $next ? __('product_builder.stages.'.$next) : '—' }}</strong>
        @if ($state->scheduled_publish_at)
            · {{ __('product_builder.governance.scheduled') }} {{ $state->scheduled_publish_at->toDayDateTimeString() }}
        @endif
    </div>
    <div style="font-size:.85rem;color:#556">
        {{ __('product_builder.governance.target_market') }}: {{ implode(', ', (array) $state->target_market) ?: '—' }} ·
        {{ __('product_builder.governance.prohibited_market') }}: {{ implode(', ', (array) $state->prohibited_market) ?: '—' }} ·
        {{ __('product_builder.governance.review_date') }}: {{ $state->next_review_date?->toDateString() ?? '—' }}
    </div>
    <table style="width:100%;font-size:.85rem;border-collapse:collapse">
        <thead><tr style="text-align:left"><th>{{ __('product_builder.governance.when') }}</th><th>{{ __('product_builder.governance.from') }}</th><th>{{ __('product_builder.governance.to') }}</th><th>{{ __('product_builder.governance.decision') }}</th><th>{{ __('product_builder.governance.notes') }}</th></tr></thead>
        <tbody>
        @forelse ($history as $h)
            <tr style="border-top:1px solid #E5E7EB"><td>{{ $h['occurred_at'] }}</td><td>{{ $h['from_stage'] }}</td><td>{{ $h['to_stage'] }}</td><td>{{ $h['decision'] }}</td><td>{{ $h['notes'] }}</td></tr>
        @empty
            <tr><td colspan="5" style="color:#667">{{ __('product_builder.governance.no_history') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
