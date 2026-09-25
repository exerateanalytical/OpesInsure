<x-filament-panels::page>
    @php($s = $summary)
    @php($colors = ['VERIFIED' => 'success', 'PLATFORM_NORMALIZED' => 'info', 'UNVERIFIED' => 'warning', 'PENDING_SOURCE' => 'danger', 'CONFIG_REQUIRED' => 'warning', 'DEMO_ONLY' => 'gray', 'RETIRED' => 'gray'])
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="text-sm font-medium text-gray-500">Domains</div>
            <div class="mt-1 text-2xl font-semibold">{{ $s['domains'] }}</div>
            <div class="mt-1 text-xs text-gray-500">Workflow data master v{{ $s['version'] }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm font-medium text-gray-500">Production-ready items</div>
            <div class="mt-1 text-2xl font-semibold">{{ $s['production_ready'] }} / {{ $s['items'] }}</div>
            <div class="mt-1 text-xs text-gray-500">Only {{ implode(' and ', $production) }} values are used in production</div>
        </x-filament::section>
        @foreach (['PENDING_SOURCE' => 'Waiting for an official / owner source', 'CONFIG_REQUIRED' => 'Waiting for configuration'] as $code => $hint)
            <x-filament::section>
                <div class="text-sm font-medium text-gray-500">{{ $code }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $s['by_status'][$code] }}</div>
                <div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Filter">
        <div class="flex flex-wrap gap-2">
            <x-filament::button size="sm" :color="$statusFilter === null ? 'primary' : 'gray'" wire:click="$set('statusFilter', null)">All</x-filament::button>
            @foreach ($codes as $code)
                <x-filament::button size="sm" :color="$statusFilter === $code ? 'primary' : 'gray'" wire:click="$set('statusFilter', '{{ $code }}')">{{ $code }} ({{ $s['by_status'][$code] }})</x-filament::button>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Registry" description="Status is computed from the platform's canonical tables where the data exists; otherwise the owner's declared status is shown.">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-xs uppercase text-gray-500">
                        <th class="py-2 pr-3">Domain</th><th class="py-2 pr-3">Item</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Owner</th>
                        <th class="py-2 pr-3">Source</th><th class="py-2 pr-3">Missing</th><th class="py-2">Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $i)
                        <tr class="border-b border-gray-100 align-top">
                            <td class="py-2 pr-3 font-medium">{{ $i['domain'] }}</td>
                            <td class="py-2 pr-3">{{ $i['item'] }}</td>
                            <td class="py-2 pr-3">
                                <x-filament::badge :color="$colors[$i['status']] ?? 'gray'">{{ $i['status'] }}</x-filament::badge>
                                @if ($i['declared_status'] && $i['declared_status'] !== $i['status'])
                                    <div class="mt-1 text-xs text-gray-500">declared {{ $i['declared_status'] }}</div>
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $i['owner'] }}</td>
                            <td class="py-2 pr-3 text-xs">{{ $i['source'] }}</td>
                            <td class="py-2 pr-3 text-xs">
                                @forelse ($i['missing'] as $m)
                                    <div>{{ $m }}</div>
                                @empty
                                    <span class="text-gray-400">—</span>
                                @endforelse
                            </td>
                            <td class="py-2 text-xs text-gray-500">{{ $i['evidence'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
