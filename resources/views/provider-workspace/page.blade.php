@php($p = $this->viewPayload())
<x-filament-panels::page>
    @if ($this->extraView())
        @include($this->extraView())
    @endif
    <div wire:offline class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm" data-state="OFFLINE">{{ __('provider_workspace.states.OFFLINE') }}</div>
    <div wire:loading class="text-sm text-gray-500" data-state="LOADING">{{ __('provider_workspace.states.LOADING') }}</div>


    @if ($p['cards'] !== [])
        <div class="grid gap-4 md:grid-cols-4">
            @foreach ($p['cards'] as $label => $value)
                <x-filament::section compact>
                    <div class="text-xs uppercase text-gray-500">{{ str_replace('_', ' ', $label) }}</div>
                    <div class="text-xl font-semibold">{{ is_numeric($value) ? number_format((float) $value) : ($value ?? '—') }}</div>
                </x-filament::section>
            @endforeach
        </div>
    @endif

    @if (in_array($p['state'], ['EMPTY', 'ERROR', 'PERMISSION_DENIED', 'INSURER_UNAVAILABLE', 'MANUAL_REVIEW_REQUIRED', 'VALIDATION_FAILED'], true))
        <x-filament::section>
            <p class="text-sm" data-state="{{ $p['state'] }}">{{ $p['message'] }}</p>
        </x-filament::section>
    @endif

    @if ($p['rows'] !== [])
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50"><tr>
                    @foreach ($p['columns'] as $c)<th class="px-3 py-2 font-medium">{{ str_replace('_', ' ', $c) }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach ($p['rows'] as $row)
                        <tr class="border-t border-gray-100">
                            @foreach ($p['columns'] as $c)<td class="px-3 py-2">{{ $row[$c] ?? '' }}</td>@endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
