<x-filament-panels::page>
    @php($c = $summary['counts'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['CIMA branches (Art. 328)', $c['branches'], $c['reserved_branches'].' reserved'],
            ['Microinsurance branches (Art. 717)', $c['micro_branches'], null],
            ['Reporting categories (Art. 411)', $c['reporting_categories'], $c['intermediary_measures'].' intermediary measures (Art. 557)'],
            ['Controlled terms (FR/EN)', $c['terms'], $c['legal_references'].' legal references'],
            ['Products', $c['products'], $c['pending_mappings'].' mapping(s) awaiting approval'],
            ['Unmapped products', $c['products_unmapped'], 'No PRIMARY CIMA branch'],
            ['Products blocked for publication', $c['products_blocked'], 'Missing insurer authorization or invalid mapping'],
            ['Insurers without authorization', $c['insurers_without_authorization'], $c['pending_authorizations'].' authorization(s) awaiting approval'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                @if ($hint)
                    <div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Products blocked for publication" description="Already-published products are grandfathered: they stay on sale, but new versions cannot be published until the insurer's authorization is recorded.">
        @forelse ($summary['blocked_products'] as $p)
            <div class="border-b border-gray-100 py-2 text-sm">
                <span class="font-medium">{{ $p['code'] }}</span> · {{ $p['name'] }} · {{ $p['carrier'] }} · {{ $p['status'] }}@if ($p['grandfathered']) · <span class="text-warning-600">grandfathered</span>@endif
                <ul class="mt-1 list-disc pl-5 text-xs text-gray-600">
                    @foreach ($p['reasons'] as $r)<li>{{ $r }}</li>@endforeach
                </ul>
            </div>
        @empty
            <div class="text-sm text-gray-500">No product is blocked.</div>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Unmapped products" description="No PRIMARY CIMA branch: the product's class has no default mapping. Propose one under Product mapping.">
        @forelse ($summary['unmapped_products'] as $p)
            <div class="py-1 text-sm">{{ $p['code'] }} · {{ $p['name'] }} ({{ $p['line_code'] }}, {{ $p['status'] }}) · {{ $p['carrier'] }}</div>
        @empty
            <div class="text-sm text-gray-500">Every product has a PRIMARY CIMA branch.</div>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Insurers without an active CIMA authorization" description="Record the agrément and authorized branches from regulator evidence under Insurer branch authorization." collapsible collapsed>
        @foreach ($summary['insurers_without_authorization'] as $i)
            <div class="py-1 text-sm">{{ $i['name'] }}@if ($i['is_demo']) (DEMO)@endif · {{ $i['products'] }} product(s)</div>
        @endforeach
    </x-filament::section>

    <x-filament::section heading="CIMA-ready checklist" :description="$checklist['source_note'].' PASS '.$checklist['totals']['PASS'].' · FAIL '.$checklist['totals']['FAIL'].' · WARN '.$checklist['totals']['WARN'].' · MANUAL '.$checklist['totals']['MANUAL']" collapsible>
        @foreach ($checklist['items'] as $i)
            <div class="border-b border-gray-100 py-1 text-sm">
                <span class="font-mono text-xs">{{ $i['id'] }}</span>
                <span @class(['font-semibold', 'text-success-600' => $i['status'] === 'PASS', 'text-danger-600' => $i['status'] === 'FAIL', 'text-warning-600' => $i['status'] === 'WARN', 'text-gray-500' => $i['status'] === 'MANUAL'])>{{ $i['status'] }}</span>
                · {{ $i['label'] }} <span class="text-xs text-gray-500">({{ $i['source'] }})</span>@if ($i['detail']) · <span class="text-xs">{{ $i['detail'] }}</span>@endif
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
