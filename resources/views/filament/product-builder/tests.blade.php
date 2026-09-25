{{-- REQ-PRD-008 test policy pack + sandbox runs (rules + rating + documents, no business records). --}}
<div style="display:grid;gap:.75rem">
    <div>
        @if ($latest)
            {{ __('product_builder.tests.latest') }}: <strong>{{ $latest['run']->status }}</strong> ({{ $latest['run']->cases_total - $latest['run']->cases_failed }}/{{ $latest['run']->cases_total }})
            @unless ($latest['current'])
                · <span style="color:#98272E">{{ __('product_builder.tests.stale') }}</span>
            @endunless
        @else
            {{ __('product_builder.tests.never_run') }}
        @endif
    </div>
    <table style="width:100%;font-size:.85rem;border-collapse:collapse">
        <thead><tr style="text-align:left"><th>{{ __('product_builder.tests.code') }}</th><th>{{ __('product_builder.tests.name') }}</th><th>{{ __('product_builder.tests.expected') }}</th></tr></thead>
        <tbody>
        @forelse ($cases as $c)
            <tr style="border-top:1px solid #E5E7EB"><td>{{ $c->code }}</td><td>{{ $c->name }}</td><td><code>{{ json_encode($c->expected) }}</code></td></tr>
        @empty
            <tr><td colspan="3" style="color:#667">{{ __('product_builder.tests.no_cases') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @if ($runs->isNotEmpty())
        <table style="width:100%;font-size:.85rem;border-collapse:collapse">
            <thead><tr style="text-align:left"><th>{{ __('product_builder.governance.when') }}</th><th>{{ __('product_builder.tests.result') }}</th><th>{{ __('product_builder.tests.failed_cases') }}</th></tr></thead>
            <tbody>
            @foreach ($runs as $run)
                <tr style="border-top:1px solid #E5E7EB"><td>{{ $run->ran_at }}</td><td>{{ $run->status }}</td>
                    <td>{{ collect($run->results)->reject(fn ($r) => $r['passed'])->pluck('code')->implode(', ') ?: '—' }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
