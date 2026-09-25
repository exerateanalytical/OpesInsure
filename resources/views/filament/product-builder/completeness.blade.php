{{-- REQ-PRD-007/010 completeness checklist: blocking errors vs warnings (PRE §97–98; STA §35). --}}
<div style="display:grid;gap:.5rem" data-completeness="{{ $report['status'] }}">
    <div><strong>{{ __('product_builder.completeness.status') }}:</strong> {{ __('product_builder.completeness.'.$report['status']) }} · {{ $report['score'] }}%</div>
    @foreach ($report['checks'] as $c)
        <div data-check="{{ $c['code'] }}" style="padding:.5rem .75rem;border-radius:.5rem;border:1px solid {{ $c['passed'] ? '#B7E1C1' : ($c['blocking'] ? '#E8A9AE' : '#EAC66F') }};background:{{ $c['passed'] ? '#F0FAF3' : ($c['blocking'] ? '#FDEDEF' : '#FFF6DD') }}">
            <strong>{{ $c['passed'] ? '✓' : ($c['blocking'] ? '✕' : '!') }} {{ __('product_builder.checks.'.$c['code']) }}</strong>
            <span style="font-size:.75rem;color:#667">{{ $c['blocking'] ? __('product_builder.completeness.blocking') : __('product_builder.completeness.warning') }}</span>
            <div style="font-size:.85rem">{{ $c['detail'] }}</div>
        </div>
    @endforeach
</div>
