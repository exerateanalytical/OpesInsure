<x-filament-panels::page>
    @php($stats = $this->getStats())
    @if ($stats !== [])
        <div data-broker-stats style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(13rem,1fr))">
            @foreach ($stats as $s)
                <x-filament::section compact data-metric="{{ $s['key'] }}">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $s['label'] }}</div>
                    <div class="text-2xl font-semibold text-gray-950 dark:text-white" style="margin-top:.25rem">{{ $s['value'] }}</div>
                </x-filament::section>
            @endforeach
        </div>
    @endif

    @foreach ($this->getDetailSections() as $section)
        <x-filament::section :heading="$section['heading']">
            <dl style="display:grid;gap:.75rem;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr))">
                @foreach ($section['rows'] as $label => $value)
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm font-medium text-gray-950 dark:text-white">{{ filled($value) ? $value : '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endforeach

    {{ $this->table }}
</x-filament-panels::page>
