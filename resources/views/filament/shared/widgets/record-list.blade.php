@php($p = $this->payload())
<x-filament-widgets::widget>
    <x-filament::section :heading="$this->heading()" compact>
        @if ($p['error'])
            <p class="text-sm text-danger-600" data-state="ERROR">{{ __('dashboards.states.error') }}</p>
        @elseif ($p['rows'] === [])
            <p class="text-sm text-gray-500" data-state="EMPTY">{{ __('dashboards.states.empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="text-xs uppercase text-gray-500">
                        @foreach ($p['columns'] as $label)<th class="px-2 py-1 font-medium">{{ $label }}</th>@endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($p['rows'] as $row)
                            <tr class="border-t border-gray-100">
                                @foreach (array_keys($p['columns']) as $k)
                                    @php($v = $row[$k] ?? null)
                                    <td class="px-2 py-1">
                                        @if ($k === array_key_first($p['columns']) && ! empty($row['_url']))
                                            <a href="{{ $row['_url'] }}" class="text-primary-600 hover:underline">{{ $v ?? '—' }}</a>
                                        @else
                                            {{ is_scalar($v) ? $v : ($v === null ? '—' : json_encode($v)) }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
