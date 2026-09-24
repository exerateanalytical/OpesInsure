<x-filament-panels::page>
    @php($s = $summary)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Domains', $s['domains'], $s['lists'].' controlled lists'],
            ['Values', $s['values'], $s['active'].' active'],
            ['Aliases', $s['aliases'], 'Used by search (EN/FR, abbreviations)'],
            ['Open suggestions', $s['open_suggestions'], $s['duplicate_suggestions'].' with possible duplicates'],
            ['Carrier mappings', $s['carrier_mappings'], $s['broker_mappings'].' broker mappings'],
            ['Changes (30 days)', $s['changes_30d'], 'Seeds, edits, merges, reviews'],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($value) }}</div>
                <div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Data sources (MDM-012)" description="Provenance of every value (source_type).">
        @foreach ($s['by_source'] as $type => $n)
            <div class="flex justify-between border-b border-gray-100 py-1 text-sm"><span>{{ $type }}</span><span class="font-medium">{{ number_format($n) }}</span></div>
        @endforeach
    </x-filament::section>

    <x-filament::section heading="Completeness per domain" description="Data completeness (translated, sourced, active) — not a business rating.">
        @foreach ($completeness as $c)
            <div class="flex items-center gap-3 border-b border-gray-100 py-1 text-sm">
                <span class="w-48">{{ $c['domain'] }}</span>
                <div class="h-2 flex-1 rounded bg-gray-100"><div class="h-2 rounded bg-primary-600" style="width: {{ $c['completeness'] }}%"></div></div>
                <span class="w-24 text-right">{{ $c['completeness'] }}% · {{ $c['values'] }}</span>
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
