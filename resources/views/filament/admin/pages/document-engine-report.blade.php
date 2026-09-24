<x-filament-panels::page>
    @if (!empty($cards))
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($cards as [$label, $value, $hint])
                <x-filament::section>
                    <div class="text-sm font-medium text-gray-500">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                    @if ($hint)<div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>@endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
    @foreach ($sections as $section)
        <x-filament::section :heading="$section['title']" :description="$section['description'] ?? null">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr>@foreach ($section['headers'] as $h)<th class="border-b px-2 py-1 font-semibold">{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($section['rows'] as $row)
                            <tr>@foreach ($row as $cell)<td class="border-b border-gray-100 px-2 py-1 align-top">{{ is_bool($cell) ? ($cell ? 'yes' : 'no') : (is_array($cell) ? implode(', ', $cell) : $cell) }}</td>@endforeach</tr>
                        @empty
                            <tr><td class="px-2 py-2 text-gray-500" colspan="{{ count($section['headers']) }}">Nothing to show.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
