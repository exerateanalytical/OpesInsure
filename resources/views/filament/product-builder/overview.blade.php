{{-- REQ-PRD-010 INS-PRODUCT-VERSION overview: branch → class → family → carrier product → version → coverages. --}}
<div style="display:grid;gap:.5rem">
    <div><strong>{{ __('product_builder.overview.class') }}:</strong> {{ $tree['class']['code'] ?? '—' }} · <strong>{{ __('product_builder.overview.family') }}:</strong> {{ $tree['family']['code'] ?? '—' }}</div>
    <div><strong>{{ __('product_builder.overview.branches') }}:</strong> {{ collect($tree['cima_branches'] ?? [])->pluck('code')->implode(', ') ?: '—' }}</div>
    <div><strong>{{ __('product_builder.overview.coverages') }}:</strong>
        @forelse ($tree['coverages'] ?? [] as $c)
            <span style="display:inline-block;margin:.1rem .25rem;padding:.1rem .5rem;border-radius:999px;background:#EEF2F7">{{ $c['code'] }} · {{ $c['inclusion'] ?? '' }}</span>
        @empty
            —
        @endforelse
    </div>
</div>
