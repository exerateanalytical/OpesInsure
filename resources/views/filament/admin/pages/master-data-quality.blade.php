<x-filament-panels::page>
    @php($q = $quality)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Untranslated (FR = EN)', count($q['untranslated'])],
            ['Duplicate labels in a list', $q['duplicates']],
            ['Unverified user submissions', $q['unverified']],
            ['Unused active values', $q['unused']],
            ['Mappings to inactive values', $q['failed_mappings']],
            ['Stale (past effective_until)', $q['stale']],
            ['Empty lists', count($q['empty_lists'])],
            ['Structure-only lists', count($q['structure_only'])],
        ] as [$label, $value])
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($value) }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="High-frequency manual entries" description="Most submitted Other / Not listed texts — review these first.">
        @forelse ($q['high_frequency_manual'] as $r)
            <div class="flex justify-between border-b border-gray-100 py-1 text-sm"><span>{{ $r->raw_input }} <span class="text-gray-500">({{ $r->domain_code }}.{{ $r->list_code }})</span></span><span class="font-medium">× {{ $r->submission_count }}</span></div>
        @empty
            <p class="text-sm text-gray-500">No open suggestions.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Structure-only lists (awaiting verified sources)">
        @foreach ($q['structure_only'] as $l)
            <div class="border-b border-gray-100 py-1 text-sm"><span class="font-medium">{{ $l->domain_code }}.{{ $l->code }}</span> — <span class="text-gray-600">{{ $l->note }}</span></div>
        @endforeach
    </x-filament::section>

    <x-filament::section heading="Untranslated values (first 200)">
        @forelse ($q['untranslated'] as $v)
            <div class="border-b border-gray-100 py-1 text-sm">{{ $v->domain_code }}.{{ $v->list_code }} · <span class="font-medium">{{ $v->code }}</span> · {{ $v->label_en }}</div>
        @empty
            <p class="text-sm text-gray-500">Every value has a distinct French label.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Completeness per domain" description="Data completeness, not a business rating.">
        @foreach ($completeness as $c)
            <div class="flex justify-between border-b border-gray-100 py-1 text-sm"><span>{{ $c['domain'] }}</span><span>{{ $c['completeness'] }}% ({{ $c['values'] }} values)</span></div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
