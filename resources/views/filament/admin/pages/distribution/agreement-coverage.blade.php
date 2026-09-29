<x-filament-panels::page>
    <x-filament::section :heading="__('bulk_agreements.coverage.zero_heading')" :description="__('bulk_agreements.coverage.zero_help')">
        @forelse ($zero as $z)
            <div class="py-1 text-sm">{{ $z['label'] }} <span class="text-xs text-gray-500">({{ $z['type'] }})</span></div>
        @empty
            <div class="text-sm text-gray-500">{{ __('bulk_agreements.coverage.zero_none') }}</div>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('bulk_agreements.coverage.matrix')">
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead>
                    <tr><th class="p-2 text-left">{{ __('bulk_agreements.coverage.broker') }}</th>
                        @foreach ($carriers as $label)<th class="p-2 text-left">{{ $label }}</th>@endforeach
                        <th class="p-2 text-left">{{ __('bulk_agreements.coverage.sellable') }}</th></tr>
                </thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="p-2">{{ $r['label'] }} <span class="text-xs text-gray-500">({{ $r['type'] }})</span></td>
                        @foreach ($carriers as $cid => $label)
                            @php($s = $r['cells'][$cid] ?? null)
                            <td class="p-2 {{ $s === 'ACTIVE' ? 'text-success-600 font-medium' : 'text-gray-400' }}">{{ $s === null ? __('bulk_agreements.coverage.none') : __('bulk_agreements.status_agreement.'.$s) }}</td>
                        @endforeach
                        <td class="p-2 {{ $r['sellable'] === 0 ? 'text-danger-600' : '' }}">{{ $r['sellable'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
