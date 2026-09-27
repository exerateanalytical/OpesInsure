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

    @if ($p['state'] === 'SUCCESS' && $this->stateMessage)
        <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm" data-state="SUCCESS">{{ $this->stateMessage }}</div>
    @endif

    @php($detail = $this->detailPayload())
    @if ($detail)
        <x-filament::section data-detail="{{ $this->selected }}">
            <x-slot name="heading">{{ $detail['title'] }}</x-slot>
            <x-slot name="afterHeader"><x-filament::link tag="button" wire:click="closeDetail">{{ __('provider_workspace.ui.close') }}</x-filament::link></x-slot>
            @if ($detail['error'])
                <p class="text-sm text-danger-600" data-state="ERROR">{{ $detail['error'] }}</p>
            @endif
            @if ($detail['cards'] !== [])
                <dl class="grid gap-3 md:grid-cols-4 mb-4">
                    @foreach ($detail['cards'] as $label => $value)
                        <div><dt class="text-xs uppercase text-gray-500">{{ str_replace('_', ' ', $label) }}</dt><dd class="font-semibold">{{ is_scalar($value) || $value === null ? ($value ?? '—') : json_encode($value) }}</dd></div>
                    @endforeach
                </dl>
            @endif
            @if ($detail['rows'] !== [])
                @php($dcols = array_keys((array) $detail['rows'][0]))
                <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                    <thead class="bg-gray-50"><tr>@foreach ($dcols as $c)<th class="px-3 py-2 font-medium">{{ str_replace('_', ' ', $c) }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($detail['rows'] as $dr)<tr class="border-t border-gray-100">@foreach ($dcols as $c)@php($v = ((array) $dr)[$c] ?? '')<td class="px-3 py-2">{{ is_scalar($v) || $v === null ? $v : json_encode($v) }}</td>@endforeach</tr>@endforeach</tbody>
                </table></div>
            @endif
        </x-filament::section>
    @endif

    @if ($p['rows'] !== [])
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50"><tr>
                    @foreach ($p['columns'] as $c)<th class="px-3 py-2 font-medium">{{ str_replace('_', ' ', $c) }}</th>@endforeach
                    <th class="px-3 py-2"></th>
                </tr></thead>
                <tbody>
                    @foreach ($p['rows'] as $row)
                        <tr class="border-t border-gray-100">
                            @foreach ($p['columns'] as $c)<td class="px-3 py-2">{{ $row[$c] ?? '' }}</td>@endforeach
                            <td class="px-3 py-2 whitespace-nowrap">
                                @foreach ($this->rowActions($row) as $a)
                                    @if (isset($a['url']))
                                        <x-filament::link :href="$a['url']" target="_blank" size="sm">{{ $a['label'] }}</x-filament::link>
                                    @else
                                        <x-filament::link tag="button" size="sm" wire:click="{{ $a['action'] }}('{{ $a['arg'] }}')">{{ $a['label'] }}</x-filament::link>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
