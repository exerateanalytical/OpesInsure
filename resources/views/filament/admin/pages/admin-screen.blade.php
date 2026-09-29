<x-filament-panels::page>
    @php($kpis = $this->kpis())
    @if ($kpis !== [])
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($kpis as $k)
                <x-filament::section>
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $k['label'] }}</div>
                    <div @class([
                        'mt-1 text-2xl font-semibold',
                        'text-danger-600' => $k['tone'] === 'danger',
                        'text-warning-600' => $k['tone'] === 'warning',
                        'text-success-600' => $k['tone'] === 'success',
                    ])>{{ $k['value'] }}</div>
                    @if (! empty($k['hint']))
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $k['hint'] }}</div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif

    @if ($extra = $this->extraView())
        @include($extra[0], $extra[1])
    @endif

    @if ($this->showTable())
        {{ $this->table }}
    @endif
</x-filament-panels::page>
